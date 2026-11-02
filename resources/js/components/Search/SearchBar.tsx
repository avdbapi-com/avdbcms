import {useEffect, useState, useRef} from 'preact/hooks';
import {useTranslation} from "react-i18next";
import clsx from 'clsx';
import {QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {useSearchQuery, useClickOutside, useKeyboardNavigate} from "@/hooks";
import {search} from "@/api/avdbclip";
import {SearchVideoType} from "@/types";
import {FormEvent} from "react";
import {highlightTyped, sanitizeSearchHighlight} from "./highlight";
import {localePath, siteFile} from "@/functions/sitePath";

type Props = {
    query?: string,
    responsive?: boolean,
    searchurl?: string,
    // From the server-printed form the component replaces (components/index.ts): the text typed there and whether it
    // had the focus.
    typed?: string,
    refocus?: string | boolean,
}

function Main ({responsive = true, searchurl, typed, refocus} : Props) {
    const { t } = useTranslation();
    const form = useRef<HTMLFormElement>(null);
    const inputId = useRef<string>('avd-search-' + Math.random().toString(36).slice(2));
    const resultsId = `${inputId.current}-results`;
    const composing = useRef(false);
    const searchParams = new URLSearchParams(window.location.search).get('q') ?? '';
    const [query, setQuery] = useState<string>(typeof typed === 'string' && typed !== '' ? typed : searchParams);
    const [showResults, setShowResults] = useState<boolean>(false);
    const input = useRef<HTMLInputElement>(null);

    // The server form had the focus: the field keeps it, with the caret after the typed text.
    useEffect(() => {
        if (refocus === undefined || refocus === false || !input.current) {
            return;
        }

        const field = input.current;
        field.focus({preventScroll: true});
        field.setSelectionRange(field.value.length, field.value.length);

        if (field.value.trim() !== '') {
            setShowResults(true);
        }
    }, []);
    const trimmedQuery = query.trim();
    // Both header layouts receive the same server-generated, localized route; without it the page's language with the
    // site's folder and /index.php where its links carry them (sitePath.ts).
    const searchAction = searchurl || localePath('/search');
    const resultsUrl = `${searchAction}?${new URLSearchParams({q: trimmedQuery})}`;

    const {data, isFetching, isError} = useSearchQuery({
        query: trimmedQuery,
        key: 'search',
        enabled: showResults,
        searchFn: async (term: string) => ({...await search(term), query: term}),
    });
    // A debounced response for the previous input must never be selectable.
    const results = data?.query === trimmedQuery ? data : undefined;

    const {index, navigate, resetIndex} = useKeyboardNavigate({
        length: results ? results.items.length + 1 : 0,
        onSelect: (index: number) => {
            window.location.href = results?.items[index]?.url ?? resultsUrl;
        },
        onDefaultSelect: () => {
            if (trimmedQuery.length > 0) {
                window.location.href = resultsUrl;
            }
        }
    });

    useClickOutside(form, () => {
        setShowResults(false);
        resetIndex();
    })

    const resultsVisible = showResults && trimmedQuery.length > 0 && Boolean(results || isError);
    const activeOptionId = resultsVisible && index !== null
        ? `${resultsId}-option-${index === results?.items.length ? 'search' : index}`
        : undefined;

    const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
        if (composing.current || trimmedQuery.length === 0) {
            e.preventDefault();
            return
        }

        if (index !== null) {
            e.preventDefault();
            window.location.href = results?.items[index]?.url ?? resultsUrl;
        }
    }

    // One update per key (watch-45): onInput only; onChange fired again for the same keystroke.
    const handleInput = (e: Event) => {
        const value = (e.currentTarget as HTMLInputElement).value;
        setQuery(value)
        setShowResults(value.trim().length > 0)
        resetIndex()
    }

    return (
        <form ref={form} method="GET" className={clsx('avd-search-wrap w-100 mb-0', responsive && 'avd-search-responsive')} role="search" action={searchAction} onSubmit={handleSubmit} autoComplete="off">
            <div className="input-group flex-nowrap">
                <input type="hidden" name="q" value={trimmedQuery} />
                <input
                    ref={input}
                    onClick={() => setShowResults(true)}
                    onFocus={() => setShowResults(true)}
                    className="form-control"
                    type="search"
                    id={inputId.current}
                    placeholder={t("Search")}
                    aria-label={t("Search")}
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded={resultsVisible}
                    aria-controls={resultsVisible ? resultsId : undefined}
                    aria-activedescendant={activeOptionId}
                    aria-busy={isFetching}
                    value={query}
                    onInput={handleInput}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            setShowResults(false);
                            resetIndex();
                            return;
                        }

                        if (!composing.current) navigate(event);
                    }}
                    onCompositionStart={() => { composing.current = true; }}
                    onCompositionEnd={() => { composing.current = false; }}
                    maxLength={255}
                    autoComplete="off"
                    autoCorrect="off"
                    autoCapitalize="none"
                    spellcheck={false}
                    inputMode="search"
                />
                {/* The search icon stays put while suggestions load (it turned into a spinner on every keystroke, watch-45);
                    the field says it is busy (aria-busy). */}
                <button className="btn" type="submit" aria-label={t("Search")}>
                    <i className="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                </button>
            </div>
            {resultsVisible && (
                <div className={clsx('avd-search-results', !responsive && 'avd-search-results-floating')}>
                    {results?.categories && results.categories.length > 0 && (
                        <div className="d-flex align-items-center flex-wrap gap-2 px-3 py-2 border-bottom" style={{background: 'rgba(255,255,255,0.03)'}}>
                            {/* the label in the reader's punctuation ("Catégories :", "分类："; 1.2.3 round 3, R3-048: a
                                hard-coded ":"); nsSeparator off: i18next would read the key's colon as a namespace */}
                            <span className="text-muted text-uppercase fw-bold" style={{fontSize: '11px', letterSpacing: '0.5px'}}>{t(':label:', {nsSeparator: false}).replace(':label', () => t('Categories'))}</span>
                            {results.categories.map((cat) => (
                                <a key={cat.id} href={cat.url} className="badge bg-secondary text-decoration-none px-2 py-1" style={{fontSize: '11px'}}>
                                    {cat.title}
                                </a>
                            ))}
                        </div>
                    )}
                    <ul id={resultsId} className="list-unstyled mb-0" role="listbox" aria-label={t("Search")}>
                        {results?.items.map((result, key) => (
                            <ResultItem
                                result={result}
                                key={key}
                                optionId={`${resultsId}-option-${key}`}
                                isSelected={index === key}
                                query={trimmedQuery}
                            />
                        ))}
                        {results && results.items.length === 0 && !results.categories?.length && (
                            <li className="px-3 py-2 text-muted" role="status">{t('No results found')}</li>
                        )}
                        <li className="border-top">
                            <a
                                id={`${resultsId}-option-search`}
                                role="option"
                                aria-selected={index === results?.items.length}
                                tabIndex={-1}
                                className={clsx("d-flex justify-content-center text-decoration-none text-muted px-3 py-2 fw-bold", index === results?.items.length && 'selected')}
                                href={resultsUrl}
                            >
                                {t('Search')}
                            </a>
                        </li>
                    </ul>
                </div>
            )}
        </form>
    )
}

type ResultItemProps = {
    result: SearchVideoType
    optionId: string
    isSelected : boolean
    query: string
}

function ResultItem ({result, optionId, isSelected, query} : ResultItemProps) {
    const [loading, setLoading] = useState(true);
    // The search engine marks the matches (_formatted, <em>); the database search does not: the typed words are marked
    // here, on the escaped text (watch-45).
    const formattedTitle = sanitizeSearchHighlight(result._formatted?.title) || highlightTyped(result.title, query);
    const formattedUser = sanitizeSearchHighlight(result._formatted?.user) || highlightTyped(result.user, query);

    return (
        <li role="none">
            <a
                id={optionId}
                role="option"
                aria-selected={isSelected}
                tabIndex={-1}
                href={result.url}
                className={clsx("avd-search-result d-flex align-items-start gap-3 text-decoration-none px-3 py-2", isSelected && 'selected')}
            >
                <span className="avd-search-thumb">
                    {loading && <span className="avd-placeholder avd-image-skeleton d-block"></span>}
                    <img className={clsx(loading && 'avd-image-loading')} src={result.thumbnail || siteFile('/images/placeholders/no-image.svg')} alt="" loading="lazy" onLoad={() => setLoading(false)} onError={(event) => {
                        const image = event.currentTarget;

                        if (image.src.endsWith('/images/placeholders/no-image.svg')) {
                            setLoading(false);
                            return;
                        }

                        image.src = siteFile('/images/placeholders/no-image.svg');
                    }} />
                </span>
                <span className="min-w-0">
                    {formattedTitle ? (
                        <span className="d-block fw-bold text-break avd-search-hit" dangerouslySetInnerHTML={{__html: formattedTitle}}></span>
                    ) : (
                        <span className="d-block fw-bold text-break">{result.title}</span>
                    )}
                    {formattedUser ? (
                        <span className="d-block text-muted text-sm avd-search-hit" dangerouslySetInnerHTML={{__html: formattedUser}}></span>
                    ) : (
                        <span className="d-block text-muted text-sm">{result.user}</span>
                    )}
                </span>
            </a>
        </li>
    )
}

export function SearchBar(props: Props) {
    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            }
        }
    });

    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}
