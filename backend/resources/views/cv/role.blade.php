<article class="role">
    <h3>{{ $role->employer }}@if ($role->title !== null)<span class="title">{{ $role->title }}</span>@endif</h3>

    @if ($role->dates !== null)
        <p class="dates">{{ $role->dates }}</p>
    @endif

    @if ($role->context !== null)
        <p class="context">{{ $markdown->block($role->context) }}</p>
    @endif

    @if ($role->items !== [])
        @include('cv.items', ['items' => $role->items])
    @endif
</article>
