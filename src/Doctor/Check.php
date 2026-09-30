<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

interface Check
{
    public function inspect(Inspection $inspection): array;
}
