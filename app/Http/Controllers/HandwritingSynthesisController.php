<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Log;

class HandwritingSynthesisController extends Controller
{
    /**
     * Synthesize handwriting from text.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function synthesize(Request $request)
    {
        $request->validate([
            'text' => 'required|string',
            'alphabet_json' => 'nullable|string', // Optional: base64 encoded alphabet.json
            'color' => 'nullable|string', // Optional: stroke color (e.g., #000000)
            'thickness' => 'nullable|numeric', // Optional: stroke thickness
        ]);

        $text = $request->input('text');
        $color = $request->input('color', '#000000');
        $thickness = $request->input('thickness', 2);
        $alphabetJson = $request->input('alphabet_json'); // Base64 encoded JSON

        try {
            // Construct the command to execute the Node.js script
            $command = [
                'node',
                base_path('scripts/handwriting-engine/engine.mjs'),
                base6_path('scripts/handwriting-engine/alphabet.json'), // Path to default alphabet
                $text,
                $color,
                $thickness,
            ];

            // If a custom alphabet JSON is provided, pass it as an argument
            if ($alphabetJson) {
                $command[] = $alphabetJson; // This will be the 5th argument (index 4)
            }

            $process = new Process($command);
            $process->setTimeout(60); // Set a timeout for the process
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = $process->getOutput();
            $strokeData = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Handwriting Synthesis: Invalid JSON output from Node.js script', ['output' => $output]);
                return response()->json(['error' => 'Failed to parse stroke data from engine.'], 500);
            }

            return response()->json([
                'stroke_data' => $strokeData
            ]);

        } catch (ProcessFailedException $exception) {
            Log::error('Handwriting Synthesis: Node.js script failed', [
                'command' => $exception->getProcess()->getCommandLine(),
                'error' => $exception->getMessage(),
                'output' => $exception->getProcess()->getOutput(),
                'error_output' => $exception->getProcess()->getErrorOutput(),
            ]);
            return response()->json(['error' => 'Handwriting synthesis failed.', 'details' => $exception->getProcess()->getErrorOutput()], 500);
        } catch (\Exception $e) {
            Log::error('Handwriting Synthesis: An unexpected error occurred', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
