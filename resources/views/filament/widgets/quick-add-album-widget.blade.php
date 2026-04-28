<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Mirror a new album
        </x-slot>

        <x-slot name="description">
            Paste a public shared-link URL from any Immich instance. We'll create the album and you can finish configuring it on the next page.
        </x-slot>

        <form wire:submit="create">
            {{ $this->form }}

            <div style="margin-top: 1rem;">
                <x-filament::button type="submit" icon="heroicon-o-plus">
                    Create album
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-widgets::widget>
