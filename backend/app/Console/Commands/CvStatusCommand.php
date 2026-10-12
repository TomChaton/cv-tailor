<?php

namespace App\Console\Commands;

use App\Observability\Status\StatusSection;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;
use RuntimeException;

class CvStatusCommand extends Command
{
    protected $signature = 'cv:status';

    protected $description = 'Report what the stack is doing right now.';

    public function handle(Container $container): int
    {
        foreach (Config::array('cv.status_sections') as $class) {
            $section = is_string($class) ? $container->make($class) : null;

            if (! $section instanceof StatusSection) {
                throw new RuntimeException('cv.status_sections holds something that is not a status section.');
            }

            $this->newLine();
            $this->line('  <fg=green;options=bold>'.$section->heading().'</>');

            foreach ($section->rows() as $label => $value) {
                $this->components->twoColumnDetail($label, $value);
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
