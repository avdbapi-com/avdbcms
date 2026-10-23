export type SearchVideoType = {
    title: string,
    user: string,
    views: string | number,
    formated_duration: string,
    published_at: string | number,
    thumbnail: string,
    url: string,
    uuid: string,
    _formatted?: {
        title: string,
        user: string,
        views: string,
        published_at: string,
        thumbnail: string,
        url: string,
        uuid: string
    }
}

export type SearchCategoryType = {
    id: number,
    title: string,
    url: string
}

export type Search = {
    items: SearchVideoType[],
    categories?: SearchCategoryType[],
    route: string,
    total: number,
}

export type SearchModel = {
    data: {
        value: string,
        label: string
    }[]
}
