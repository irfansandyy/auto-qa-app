#!/usr/bin/env python3

from __future__ import annotations

import argparse
import codecs
import fcntl
import os
import re
import stat
import sys
import tempfile
from pathlib import Path


DECLARATION = re.compile(rb"[ \t]*(?:export[ \t]+)?([A-Za-z0-9_.-]+)[ \t]*(?:([=:])[ \t]*(.*)|#.*)?")


class SyncError(Exception):
    pass


def after_quote(value: bytes, quote: int) -> bytes | None:
    escaped = False
    for index, character in enumerate(value):
        if escaped:
            escaped = False
        elif character == ord("\\"):
            escaped = True
        elif character == quote:
            return value[index + 1 :]
    return None


def declarations(content: bytes, label: str, *, template: bool = False) -> dict[bytes, bytes]:
    if b"\0" in content:
        raise SyncError(f"The {label} contains a null byte.")

    if content.startswith(codecs.BOM_UTF8):
        content = content[len(codecs.BOM_UTF8) :]
    entries = {}
    lines = iter(enumerate(content.splitlines(keepends=True), start=1))
    for line_number, raw_line in lines:
        line = raw_line.rstrip(b"\r\n")
        if not line.strip() or line.lstrip().startswith(b"#"):
            continue

        match = DECLARATION.fullmatch(line)
        if match is None or (template and match[2] is None):
            raise SyncError(f"Invalid declaration at line {line_number} in the {label}.")

        name, _, value = match.groups()
        raw_entry = [raw_line]
        if value and value[:1] in (b"'", b'"'):
            quote = value[0]
            trailing = after_quote(value[1:], quote)
            while trailing is None:
                continued = next(lines, None)
                if continued is None:
                    raise SyncError(f"Unclosed quoted value at line {line_number} in the {label}.")
                _, continuation = continued
                raw_entry.append(continuation)
                trailing = after_quote(continuation.rstrip(b"\r\n"), quote)
            trailing = trailing.lstrip()
            if trailing and not trailing.startswith(b"#"):
                raise SyncError(f"Unexpected text after a quoted value at line {line_number} in the {label}.")

        if name in entries:
            if template:
                raise SyncError(f"Duplicate declaration at line {line_number} in the {label}.")
            print(f"Preserved duplicate declaration at line {line_number} in the {label}.", file=sys.stderr)
        entries[name] = b"".join(raw_entry)

    return entries


def open_regular(path: Path, flags: int, label: str) -> int:
    try:
        descriptor = os.open(path, flags | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    except OSError as exception:
        raise SyncError(f"Could not open the {label}: {exception.strerror}.") from None

    if not stat.S_ISREG(os.fstat(descriptor).st_mode):
        os.close(descriptor)
        raise SyncError(f"The {label} must be a regular file.")
    return descriptor


def file_version(metadata: os.stat_result) -> tuple[int, ...]:
    return (
        metadata.st_dev, metadata.st_ino, metadata.st_mode, metadata.st_uid,
        metadata.st_gid, metadata.st_size, metadata.st_ctime_ns, metadata.st_mtime_ns,
    )


def read_regular(path: Path, label: str) -> tuple[bytes, os.stat_result]:
    descriptor = open_regular(path, os.O_RDONLY, label)
    with os.fdopen(descriptor, "rb") as stream:
        metadata = os.fstat(descriptor)
        content = stream.read()
        if file_version(os.fstat(descriptor)) != file_version(metadata):
            raise SyncError(f"The {label} changed while it was being read.")
        return content, metadata


def append_missing(path: Path, content: bytes, original: os.stat_result, missing: list[bytes]) -> None:
    temporary = None
    newline = b"\r\n" if b"\r\n" in content else b"\n"
    try:
        with tempfile.NamedTemporaryFile(
            mode="wb", dir=path.parent, prefix=f".{path.name}.autoqa-sync-", suffix=".tmp", delete=False,
        ) as stream:
            temporary = Path(stream.name)
            stream.write(content)
            if content not in (b"", codecs.BOM_UTF8) and not content.endswith(b"\n"):
                stream.write(newline)
            for entry in missing:
                stream.write(entry)
                if not entry.endswith(b"\n"):
                    stream.write(newline)

            descriptor = stream.fileno()
            ownership = os.fstat(descriptor)
            if (ownership.st_uid, ownership.st_gid) != (original.st_uid, original.st_gid):
                os.fchown(descriptor, original.st_uid, original.st_gid)
            os.fchmod(descriptor, stat.S_IMODE(original.st_mode))
            stream.flush()
            os.fsync(descriptor)

        current = os.lstat(path)
        if file_version(current) != file_version(original):
            raise SyncError("The production dotenv changed during synchronization. Retry after the edit is complete.")
        os.replace(temporary, path)

        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def sync(template: Path, destination: Path) -> int:
    template_content, template_metadata = read_regular(template, "dotenv template")
    template_entries = declarations(template_content, "dotenv template", template=True)
    if not template_entries:
        raise SyncError("The dotenv template must contain at least one declaration.")

    lock = destination.with_name(f".{destination.name}.autoqa-sync.lock")
    descriptor = open_regular(lock, os.O_CREAT | os.O_RDWR, "dotenv synchronization lock")
    with os.fdopen(descriptor, "rb") as stream:
        fcntl.flock(stream, fcntl.LOCK_EX)
        content, metadata = read_regular(destination, "production dotenv")
        if (metadata.st_dev, metadata.st_ino) == (template_metadata.st_dev, template_metadata.st_ino):
            raise SyncError("The dotenv template and production dotenv must be separate files.")

        destination_entries = declarations(content, "production dotenv")
        missing = [entry for name, entry in template_entries.items() if name not in destination_entries]
        if missing:
            append_missing(destination, content, metadata, missing)

    return len(missing)


def main() -> int:
    parser = argparse.ArgumentParser(description="Append missing defaults to the existing production dotenv.")
    parser.add_argument("template", type=Path, help="Tracked dotenv template containing safe default values")
    parser.add_argument("destination", type=Path, help="Existing private production dotenv")
    args = parser.parse_args()
    try:
        count = sync(args.template, args.destination)
    except SyncError as exception:
        print(exception, file=sys.stderr)
        return 1
    except OSError as exception:
        print(f"Could not synchronize the production dotenv: {exception.strerror}.", file=sys.stderr)
        return 1

    print(f"Added {count} missing dotenv setting(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
