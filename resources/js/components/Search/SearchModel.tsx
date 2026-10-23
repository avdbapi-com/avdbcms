import {useState, useRef} from 'preact/hooks';
import {useSearchQuery, useClickOutside, useKeyboardNavigate} from "@/hooks";
import {QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {searchModel} from "@/api/avdbclip";
import {ChangeEvent} from "react";
import {useTranslation} from "react-i18next";
import {Loader} from "@/components/Commons";

type Props = {
    endpoint: string,
    name: string,
    label?: string | null,
    value: string | null,
}

function Main ({endpoint, name, label = null, value = null} : Props) {

    const defaultValue: { value: string, label: string} | null = value ? JSON.parse(value) : null;

    const input = useRef<HTMLInputElement>(null);
    const finalInput = useRef<HTMLInputElement>(null);
    const inputId = name;
    const resultsId = `${name}-search-results`;
    const composing = useRef(false);

    const [query, setQuery] = useState<string>(defaultValue?.label ?? '');
    const [showResults, setShowResults] = useState<boolean>(false);

    const {data: results, isFetching} = useSearchQuery({
        query: query,
        key: 'search',
        searchFn: () => searchModel(endpoint, query)
    });

    const { t } = useTranslation();

    const select = (index: number) => {
        const result = results!.data[index];
        finalInput.current!.value = result.value
        setQuery(result.label)
        setShowResults(false)
    }

    const {index, navigate, resetIndex} = useKeyboardNavigate({
        length: results?.data.length,
        onSelect: select
    });

    useClickOutside(input, () => {
        setShowResults(false);
        resetIndex();
    });

    const resultsVisible = showResults && Boolean(results);
    const activeOptionId = resultsVisible && index !== null
        ? `${resultsId}-option-${index}`
        : undefined;

    const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
        setShowResults(true)
        setQuery(e.currentTarget.value)
        resetIndex()
    }

    return (
        <div className={'position-relative'}>
            <label htmlFor={name} className="form-label fw-bold">{t(label ?? name.charAt(0).toUpperCase() + name.slice(1))}</label>
            <div className={'position-relative'}>
                <input
                    onClick={() => setShowResults(true)}
                    ref={input}
                    onChange={handleChange}
                    type="search"
                    className="form-control"
                    id={inputId}
                    placeholder={t('Search {model}', {model: t(name)})}
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded={resultsVisible}
                    aria-controls={resultsVisible ? resultsId : undefined}
                    aria-activedescendant={activeOptionId}
                    aria-busy={isFetching}
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
                    value={query}
                    maxLength={255}
                    autoComplete="off"
                />
                {
                    isFetching &&
                    <div className="position-absolute top-50 right-0 translate-middle" >
                        <Loader small={true} />
                    </div>
                }
            </div>
            {
                resultsVisible && results &&
                <div id={resultsId} className={'position-absolute w-100 rounded-2 bg-white shadow-lg border border-1'} style={{top:'75px'}}>
                    {
                        results.data.length ?
                            <ul className={'list-unstyled mb-0'} role="listbox" aria-label={t('Search')}>
                                {
                                    results.data.map((result, key) => (
                                        <li role="none" key={key}>
                                            <button
                                                type="button"
                                                role="option"
                                                id={`${resultsId}-option-${key}`}
                                                aria-selected={index === key}
                                                tabIndex={-1}
                                                className={'text-black text-start w-100 border-0 bg-transparent px-3 py-2 hover-primary cursor-pointer ' + (index === key ? 'selected' : '')}
                                                onClick={() => select(key)}
                                            >
                                                {result.label}
                                            </button>
                                        </li>
                                    ))
                                }
                            </ul>
                            :
                            <div className={'d-flex justify-content-center align-items-center flex-column py-3'} role="status" aria-live="polite">
                                <strong>{t('No results found')}</strong>
                            </div>
                    }
                </div>
            }
            <input type="hidden" name={name} ref={finalInput} defaultValue={defaultValue?.value}/>
        </div>

    )
}

export function SearchModel(props: Props) {

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

