<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CreateHandwritingAlphabetFileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:handwriting-alphabet-file';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates the alphabet.json file for the handwriting synthesis engine if it does not exist.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $directory = base_path('scripts/handwriting-engine');
        $filePath = $directory . '/alphabet.json';

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (File::exists($filePath)) {
            $this->info('alphabet.json file already exists. No action taken.');
            return 0;
        }

        $alphabetContent = <<<JSON
{
  "comment": "Este é um alfabeto vetorial de exemplo. As coordenadas são relativas a uma caixa de 40x40.",
  "l": {
    "width": 20,
    "entryPoint": { "dx": 10, "dy": 0 },
    "exitPoint": { "dx": 10, "dy": 40 },
    "strokes": [
      {
        "points": [
          { "dx": 10, "dy": 0 },
          { "dx": 10, "dy": 40 }
        ]
      }
    ]
  },
  "o": {
    "width": 40,
    "entryPoint": { "dx": 20, "dy": 0 },
    "exitPoint": { "dx": 40, "dy": 20 },
    "strokes": [
      {
        "points": [
          { "dx": 20, "dy": 0 },
          { "dx": 0, "dy": 20 },
          { "dx": 20, "dy": 40 },
          { "dx": 40, "dy": 20 },
          { "dx": 20, "dy": 0 }
        ]
      }
    ]
  },
  "a": {
    "width": 40,
    "entryPoint": { "dx": 20, "dy": 0 },
    "exitPoint": { "dx": 40, "dy": 20 },
    "strokes": [
      {
        "points": [
          { "dx": 20, "dy": 0 },
          { "dx": 0, "dy": 20 },
          { "dx": 20, "dy": 40 },
          { "dx": 40, "dy": 20 },
          { "dx": 20, "dy": 0 }
        ]
      },
      {
        "points": [
            { "dx": 40, "dy": 20 },
            { "dx": 40, "dy": 40 }
        ]
      }
    ]
  }
}
JSON;

        File::put($filePath, $alphabetContent);

        $this->info('Successfully created alphabet.json file.');
        return 0;
    }
}
