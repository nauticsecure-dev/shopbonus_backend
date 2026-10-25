<?php

namespace App\Listeners;

use App\Enums\ErrorSource;
use App\Events\ApplicationErrorRecorded;
use App\Services\Admin\IncidentRecorder;

class RecordTechnicalIncident
{
    public function __construct(private readonly IncidentRecorder $incidents) {}

    public function handle(ApplicationErrorRecorded $event): void
    {
        if ($event->error->source !== ErrorSource::Http) {
            $this->incidents->technical($event->error);
        }
    }
}
