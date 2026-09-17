/*
 * Copy-to-clipboard for any `.clipbutton`: the text comes from `data-clipboard-text`, or from the
 * value/text of the element named by `data-clipboard-target`. Uses the async Clipboard API and
 * falls back to a temporary textarea placed next to the button, so it also works inside a
 * Bootstrap modal whose focus trap would otherwise steal the selection.
 */
import $ from 'jquery';

const FEEDBACK_MS = 1500;

function textFor(button) {
    if (button.dataset.clipboardText !== undefined) {
        return button.dataset.clipboardText;
    }
    const target = button.dataset.clipboardTarget && document.querySelector(button.dataset.clipboardTarget);
    if (!target) return '';
    return 'value' in target && target.value !== undefined ? target.value : target.textContent;
}

function fallbackCopy(text, anchor) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.setAttribute('aria-hidden', 'true');
    area.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0';
    (anchor.closest('.modal-content') || document.body).append(area);
    area.focus();
    area.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    area.remove();
    anchor.focus();
    return ok;
}

function showFeedback(button, ok) {
    if (button.dataset.copyBusy) return;
    button.dataset.copyBusy = '1';
    const label = button.querySelector('.clip-label');
    const icon = button.querySelector('i, svg');
    const restore = [];
    if (label) {
        const previous = label.textContent;
        label.textContent = ok ? 'Copied!' : 'Copy failed';
        restore.push(() => { label.textContent = previous; });
    } else if (icon) {
        const replacement = document.createElement('i');
        replacement.className = ok ? 'fa-solid fa-check' : 'fa-solid fa-xmark';
        replacement.setAttribute('aria-hidden', 'true');
        icon.replaceWith(replacement);
        restore.push(() => { button.querySelector('i, svg')?.replaceWith(icon); });
    }
    button.classList.toggle('is-copied', ok);
    button.classList.toggle('is-copy-failed', !ok);
    setTimeout(() => {
        restore.forEach((fn) => fn());
        button.classList.remove('is-copied', 'is-copy-failed');
        delete button.dataset.copyBusy;
    }, FEEDBACK_MS);
}

export function copyText(text, anchor) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text).then(() => true, () => fallbackCopy(text, anchor));
    }
    return Promise.resolve(fallbackCopy(text, anchor));
}

export function setupClipboard(root = document) {
    $(root).on('click', '.clipbutton', (e) => {
        const button = e.currentTarget;
        const text = textFor(button);
        if (text === '') return;
        copyText(text, button).then((ok) => showFeedback(button, ok));
    });
}
