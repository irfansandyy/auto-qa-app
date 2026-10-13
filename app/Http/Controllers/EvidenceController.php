<?php

namespace App\Http\Controllers;

use App\Models\QaRun;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class EvidenceController extends Controller
{
    public function __invoke(QaRun $run, string $filename): BinaryFileResponse
    {
        abort_unless($run->project?->user_id === auth()->id(), 404);
        abort_unless(preg_match('/\Abrowser-[a-zA-Z0-9-]+\.(png|zip)\z/', $filename, $match), 404);
        abort_unless($this->isRecorded($run, $filename), 404);

        $directory = storage_path('app/private/evidence');
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $root = realpath($directory);
        $resolved = realpath($path);

        abort_unless($root !== false && $resolved !== false
            && ! is_link($directory) && ! is_link($path)
            && is_file($resolved) && dirname($resolved) === $root, 404);

        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($match[1] === 'zip') {
            return response()->download($resolved, $filename, [
                ...$headers, 'Content-Type' => 'application/zip',
            ])->setPrivate();
        }

        return response()->file($resolved, [...$headers, 'Content-Type' => 'image/png'])
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename)
            ->setPrivate();
    }

    private function isRecorded(QaRun $run, string $filename): bool
    {
        if ($run->findings()->where('evidence->artifact', $filename)->exists()) {
            return true;
        }

        $artifacts = $run->report['artifacts'] ?? [];
        if (! is_array($artifacts)) {
            return false;
        }
        foreach ($artifacts as $artifact) {
            if (is_array($artifact) && ($artifact['filename'] ?? null) === $filename) {
                return true;
            }
        }

        return false;
    }
}
