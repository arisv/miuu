// Client-side twin of App\Service\FileNameRules for instant feedback (the server has the last
// word), plus portability warnings: names Unix accepts but Windows and friends reserve.

export const MAX_NAME_BYTES = 255;
export const ALIAS_PATTERN = /^[A-Za-z0-9]{1,64}$/;

const encoder = new TextEncoder();
const RESERVED_DEVICE = /^(con|prn|aux|nul|conin\$|conout\$|com[0-9¹²³]|lpt[0-9¹²³])$/i;

export function byteLength(text) {
    return encoder.encode(text).length;
}

export function joinName(base, extension) {
    return extension ? base + '.' + extension : base;
}

/** First reason the name cannot be used, or null. */
export function nameProblem(base, extension) {
    if (base === '') {
        return 'The name cannot be empty.';
    }
    if (typeof base.isWellFormed === 'function' && !base.isWellFormed()) {
        return 'The name contains broken characters.';
    }
    if (base.includes('/')) {
        return 'The name cannot contain "/".';
    }
    if (base.includes('\0')) {
        return 'The name cannot contain NUL characters.';
    }
    const full = joinName(base, extension);
    if (full === '.' || full === '..') {
        return 'The name cannot be "." or "..".';
    }
    const bytes = byteLength(full);
    if (bytes > MAX_NAME_BYTES) {
        return `The name is ${bytes} bytes long; the limit is ${MAX_NAME_BYTES}.`;
    }
    return null;
}

/** Reasons the full name may not survive a copy to another system; empty when it is portable. */
export function portabilityWarnings(name) {
    const warnings = [];
    const reserved = [...new Set(name.match(/[<>:"\\|?*]/g) || [])];
    if (reserved.length) {
        warnings.push('Windows does not allow ' + reserved.join(' ') + ' in file names.');
    }
    if (/[\x00-\x1f\x7f]/.test(name)) {
        warnings.push('It contains control characters, which Windows and many apps reject.');
    }
    if (/[. ]$/.test(name)) {
        warnings.push('Windows drops a trailing dot or space.');
    }
    if (/^\s/.test(name)) {
        warnings.push('It starts with a space, which is easy to miss and some apps strip.');
    }
    const stem = name.split('.')[0].replace(/[ .]+$/, '');
    if (RESERVED_DEVICE.test(stem)) {
        warnings.push(`"${stem}" is a reserved device name on Windows.`);
    }
    return warnings;
}
