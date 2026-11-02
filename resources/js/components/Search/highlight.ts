// The search box's suggestion highlights (SearchBar.tsx), kept apart from the component so tests/js/front_logic.test.ts
// can run them in Node. Everything they return is safe markup: text is escaped, and only <em> marks are kept.

// The search engine's own highlights (result._formatted): only <em> and </em> stay markup, everything else is text.
export function sanitizeSearchHighlight(value?: string): string {
    if (!value) return '';

    let safe = '';
    let cursor = 0;
    const tagPattern = /<\/?em>/gi;
    let match: RegExpExecArray | null;

    while ((match = tagPattern.exec(value)) !== null) {
        safe += escapeHtml(value.slice(cursor, match.index));
        safe += match[0].startsWith('</') ? '</em>' : '<em>';
        cursor = match.index + match[0].length;
    }

    return safe + escapeHtml(value.slice(cursor));
}

// The typed words (2 characters or more) in a plain text, marked <em> like the search engine's highlights; '' when none
// is found (the caller then prints the text as it is). watch-45.
export function highlightTyped(text: string | undefined, query: string): string {
    if (!text) return '';

    const words = Array.from(new Set(query.toLowerCase().split(/\s+/).filter((word) => word.length >= 2))).slice(0, 6);

    if (words.length === 0) return '';

    const pattern = new RegExp('(' + words.map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'gi');
    const parts = text.split(pattern);

    if (parts.length < 2) return '';

    return parts.map((part, i) => (i % 2 === 1 ? '<em>' + escapeHtml(part) + '</em>' : escapeHtml(part))).join('');
}

export function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
