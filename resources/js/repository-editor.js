const sourceLanguages = new Set([
    'bash', 'c', 'cpp', 'cs', 'css', 'go', 'html', 'java', 'js', 'json', 'jsx', 'kt', 'less', 'mjs', 'php',
    'py', 'rb', 'rs', 'sass', 'scss', 'sh', 'sql', 'svelte', 'swift', 'toml', 'ts', 'tsx', 'vue', 'xml', 'yaml', 'yml',
]);

const keywords = new Set(
    'abstract and as async await begin break case catch class const continue def default do echo else elseif end enum export extends false finally fn for foreach from function if implements import in instanceof interface let match namespace new nil none not null or package private protected public raise readonly require return select static struct super switch this throw trait true try type typeof use using val var void where while with yield'.split(' '),
);

const tokens = /(?<comment>\/\*[\s\S]*?(?:\*\/|$)|<!--[\s\S]*?(?:-->|$)|\/\/[^\r\n]*|^[\t ]*\#[^\r\n]*)|(?<string>"(?:\\[^\r\n]|[^"\\\r\n])*"|'(?:\\[^\r\n]|[^'\\\r\n])*'|`(?:\\[^\r\n]|[^`\\\r\n])*`)|(?<tag><\/?[A-Za-z][^<>\n]*>|<\?php|\?>)|(?<number>\b(?:0[xX][0-9a-fA-F]+|\d+(?:\.\d+)?)\b)|(?<variable>\$[A-Za-z_][\w]*)|(?<word>[A-Za-z_][\w]*)/gm;

function appendToken(target, value, kind) {
    if (!kind) {
        target.append(value);
        return;
    }

    const span = document.createElement('span');
    span.className = `repo-token-${kind}`;
    span.textContent = value;
    target.append(span);
}

function renderSource(target, source, highlight, numberLines) {
    const result = document.createDocumentFragment();
    let line = result;
    let lineCount = 0;

    function addLine() {
        if (lineCount > 0) result.append('\n');
        lineCount++;
        line = document.createElement('span');
        line.className = 'repo-source-line';
        result.append(line);
    }

    function write(value, kind) {
        if (!numberLines) {
            appendToken(result, value, kind);
            return;
        }

        for (const [index, part] of value.split('\n').entries()) {
            if (index) addLine();
            appendToken(line, part, kind);
        }
    }

    if (numberLines) addLine();

    let position = 0;
    if (highlight) {
        for (const match of source.matchAll(tokens)) {
            write(source.slice(position, match.index));
            const kind = Object.keys(match.groups).find(key => match.groups[key] !== undefined);
            const style = kind === 'word' ? (keywords.has(match[0].toLowerCase()) ? 'keyword' : undefined) : kind;
            write(match[0], style);
            position = match.index + match[0].length;
        }
    }

    write(source.slice(position));
    if (numberLines) target.style.setProperty('--repo-line-digits', `${String(lineCount).length}ch`);
    target.replaceChildren(result);
}

class RepositoryCode extends HTMLElement {
    connectedCallback() {
        queueMicrotask(() => {
            if (this.isConnected && !this.cleanup) this.enhance();
        });
    }

    disconnectedCallback() {
        this.cleanup?.();
        this.cleanup = null;
    }

    enhance() {
        const code = this.querySelector('code');
        if (!code) return;

        const extension = this.dataset.path?.split('.').at(-1)?.toLowerCase();
        const highlight = sourceLanguages.has(extension);
        const editor = this.querySelector('textarea');

        if (!editor) {
            this.source ??= code.textContent;
            renderSource(code, this.source, highlight, true);
            return;
        }

        if (!highlight) return;

        const overlay = code.closest('pre');
        let frame = null;
        const syncScroll = () => {
            overlay.scrollTop = editor.scrollTop;
            overlay.scrollLeft = editor.scrollLeft;
        };
        const update = () => {
            if (frame !== null) return;
            frame = requestAnimationFrame(() => {
                frame = null;
                renderSource(code, `${editor.value}\n`, true, false);
                syncScroll();
            });
        };

        editor.addEventListener('input', update);
        editor.addEventListener('scroll', syncScroll, { passive: true });
        this.dataset.enhanced = 'true';
        update();

        this.cleanup = () => {
            editor.removeEventListener('input', update);
            editor.removeEventListener('scroll', syncScroll);
            if (frame !== null) cancelAnimationFrame(frame);
        };
    }
}

customElements.define('autoqa-code', RepositoryCode);
