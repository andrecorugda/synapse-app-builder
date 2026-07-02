<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Models\Record;

/**
 * Eloquent observer on the dynamic Record model. Every collection write goes
 * through Record::for(...), so these hooks catch all of them and forward the
 * event to the WatcherDispatcher. The dispatcher is resolved lazily (via app())
 * to avoid boot-order coupling at observer-registration time.
 *
 * The previous record state is forwarded as `old` (empty on create) so watcher
 * flows can compare before/after via `{{ input.old.* }}`.
 */
class RecordObserver
{
    public function created(Record $record): void
    {
        $this->dispatch($record, 'created');
    }

    public function updated(Record $record): void
    {
        $this->dispatch($record, 'updated');
    }

    public function deleted(Record $record): void
    {
        $this->dispatch($record, 'deleted');
    }

    private function dispatch(Record $record, string $event): void
    {
        if (($record->pbModelKey ?? '') === '') {
            return;
        }

        app(WatcherDispatcher::class)->dispatchCollectionEvent(
            $record->pbModelKey,
            $event,
            $record->toArray(),
            $event === 'created' ? [] : $record->getOriginal(),
        );
    }
}
