<div x-data="{ filters: window.innerWidth > 992 }" @resize.window="if (window.innerWidth > 992) filters = true">
    <button
        type="button"
        class="btn btn-primary btn-sm d-flex d-lg-none align-items-center gap-2 mb-3"
        @click="filters = !filters"
        aria-controls="filter-fields"
        :aria-expanded="filters.toString()"
    >
        <i class="fa-solid fa-filter"></i>
        <span>{{ __('Filters') }}</span>
        <i class="fa-solid fa-chevron-down" x-show.important="!filters" x-cloak></i>
        <i class="fa-solid fa-chevron-up" x-show.important="filters" x-cloak></i>
    </button>

    <?php if ($showStart): ?>
        <?= Form::open(array_replace($formOptions, [
            'id' => 'filter-fields',
            'x-cloak' => 'x-cloak',
            'x-show.important' => 'filters',
        ])) ?>
    <?php endif; ?>

    <?php if ($showFields): ?>
        <?php foreach ($fields as $field): ?>
            <?php if( ! in_array($field->getName(), $exclude) ) { ?>
                <?= $field->render() ?>
            <?php } ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="btn-group col-auto">
        <button type="submit" class="btn btn-outline-secondary" title="{{ __('Search') }}" aria-label="{{ __('Search') }}">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        </button>
        <a href="{{ url()->current() }}" class="btn btn-outline-secondary" title="{{ __('Reset') }}" aria-label="{{ __('Reset') }}">
            <i class="fa-solid fa-eraser" aria-hidden="true"></i>
        </a>
    </div>

    <?php if ($showEnd): ?>
        <?= Form::close() ?>
    <?php endif; ?>
</div>
