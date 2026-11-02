<?php

namespace App\Listeners;

use App\Events\Activity\ActivityCreated;
use App\Events\Activity\ActivityDeleted;
use App\Events\Activity\ActivityUpdated;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;

class ActivityEventSubscriber
{
    /**
     * Handle user have new activity event.
     */
    public function saveActivity(ActivityCreated $event): void {
      $user = Auth::user();

      if (!$user) {
          return;
      }

      $user->activity()->create([
          'subject_type' => get_class($event->model),
          'subject_id' => $event->model->id,
          'perform_at' => $this->performedAt($event->model),
      ]);
    }

    /**
     * Handle user have updated activity event.
     */
    public function updateActivity(ActivityUpdated $event): void {
        $user = Auth::user();

        if (!$user) {
            return;
        }

        $user->activity()->where([
            'subject_type' => get_class($event->model),
            'subject_id' => $event->model->id,
        ])->update([
            'perform_at' => $this->performedAt($event->model),
        ]);
    }

    /**
     * Handle user delete activity event.
     *
     * studio-18 (data loss): only the activity of THIS subject goes: its type and its id. Before, every activity whose
     * subject_id matched went too (deleting comment #13 also removed someone's "You liked ..." of interaction #13).
     */
    public function deleteActivity(ActivityDeleted $event): void {
        Activity::query()
            ->where('subject_type', get_class($event->model))
            ->where('subject_id', $event->model->id)
            ->delete();
    }

    /**
     * When the member did it, in the app's time (studio-23): an interaction keeps its moment in perform_at and has no
     * created_at, so its activity used to get none (null, and the database's own clock filled it in a different time
     * zone: likes showed 5 hours after the comments made at the same moment).
     */
    private function performedAt(Model $model): mixed
    {
        return $model->getAttribute('perform_at')
            ?? $model->getAttribute('created_at')
            ?? now();
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ActivityCreated::class => 'saveActivity',
            ActivityUpdated::class => 'updateActivity',
            ActivityDeleted::class => 'deleteActivity',
        ];
    }
}
