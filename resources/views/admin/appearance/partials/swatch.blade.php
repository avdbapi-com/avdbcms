{{-- Three colours of a colour style as a small pie (SVG fills, no inline styles). $colours: [brand, brand2, accent]. --}}
@php($c = array_values(array_pad(array_slice($colours, 0, 3), 3, $colours[0] ?? '#888888')))
<svg class="ad-pie" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 10V0a10 10 0 0 1 0 20z" fill="{{ $c[0] }}"/><path d="M10 10v10A10 10 0 0 1 1.34 5z" fill="{{ $c[1] }}"/><path d="M10 10L1.34 5A10 10 0 0 1 10 0z" fill="{{ $c[2] }}"/></svg>
