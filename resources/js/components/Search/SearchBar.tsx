import {useState, useRef} from 'preact/hooks';
import {useTranslation} from "react-i18next";
import clsx from 'clsx';
import {QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {useSearchQuery, useClickOutside, useKeyboardNavigate} from "@/hooks";
import {search} from "@/api/avdbclip";
import {SearchVideoType} from "@/types";
import {ChangeEvent, FormEvent} from "react";
import {Loader} from "@/components/Commons";

type Props = {
    query?: string,
    responsive?: boolean,
    searchurl?: string,
}

function Main ({responsive = true, searchurl} : Props) {
    const { t } = useTranslation();
    const form = useRef<HTMLFormElement>(null);
    const inputId = useRef<string>('avd-search-' + Math.random().toString(36).slice(2));
    const resultsId = `${inputId.current}-results`;
    const composing = useRef(false);
    const searchParams = new URLSearchParams(window.location.search).get('q') ?? '';
    const [query, setQuery] = useState<string>(searchParams);
    const [showResults, setShowResults] = useState<boolean>(false);
    const trimmedQuery = query.trim();
    // Both header layouts receive the same server-generated, localized route.
    const searchAction = searchurl || `${window.location.pathname.startsWith('/index.php') ? '/index.php' : ''}${window.LOCALE_PREFIX || ''}/search`;
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

    const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
        const value = e.currentTarget.value;
        setQuery(value)
        setShowResults(value.trim().length > 0)
        resetIndex()
    }

    return (
        <form ref={form} method="GET" className={clsx('avd-search-wrap w-100 mb-0', responsive && 'avd-search-responsive')} role="search" action={searchAction} onSubmit={handleSubmit} autoComplete="off">
            <div className="input-group flex-nowrap">
                <input type="hidden" name="q" value={trimmedQuery} />
                <input
                    onClick={() => setShowResults(true)}
                    onFocus={() => setShowResults(true)}
                    onChange={handleChange}
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
                    onInput={handleChange}
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
                <button className="btn" type="submit" aria-label={t("Search")}>
                    {isFetching ? <Loader small={true}/> : <i className="fa-solid fa-magnifying-glass"></i>}
                </button>
            </div>
            {resultsVisible && (
                <div className={clsx('avd-search-results', !responsive && 'avd-search-results-floating')}>
                    {results?.categories && results.categories.length > 0 && (
                        <div className="d-flex align-items-center flex-wrap gap-2 px-3 py-2 border-bottom" style={{background: 'rgba(255,255,255,0.03)'}}>
                            <span className="text-muted text-uppercase fw-bold" style={{fontSize: '11px', letterSpacing: '0.5px'}}>{t('Categories')}:</span>
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
}

function ResultItem ({result, optionId, isSelected} : ResultItemProps) {
    const [loading, setLoading] = useState(true);
    const formattedTitle = sanitizeSearchHighlight(result._formatted?.title);
    const formattedUser = sanitizeSearchHighlight(result._formatted?.user);

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
                    <img className={clsx(loading && 'avd-image-loading')} src={result.thumbnail || '/images/placeholders/no-image.svg'} alt="" loading="lazy" onLoad={() => setLoading(false)} onError={(event) => {
                        const image = event.currentTarget;

                        if (image.src.endsWith('/images/placeholders/no-image.svg')) {
                            setLoading(false);
                            return;
                        }

                        image.src = '/images/placeholders/no-image.svg';
                    }} />
                </span>
                <span className="min-w-0">
                    {formattedTitle ? (
                        <span className="d-block fw-bold text-break" dangerouslySetInnerHTML={{__html: formattedTitle}}></span>
                    ) : (
                        <span className="d-block fw-bold text-break">{result.title}</span>
                    )}
                    {formattedUser ? (
                        <span className="d-block text-muted text-sm" dangerouslySetInnerHTML={{__html: formattedUser}}></span>
                    ) : (
                        <span className="d-block text-muted text-sm">{result.user}</span>
                    )}
                </span>
            </a>
        </li>
    )
}

function sanitizeSearchHighlight(value?: string): string {
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

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
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
