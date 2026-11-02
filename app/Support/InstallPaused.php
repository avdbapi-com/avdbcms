<?php

namespace App\Support;

/**
 * Thrown by the web installer between two database changes when this request's time is used up
 * (App\Support\Installer::runStep()): the change about to start has not started, nothing of it is applied, and the next
 * request of the installation runs it (R1B-001). Never an error.
 */
final class InstallPaused extends \RuntimeException
{
}
