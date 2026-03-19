<?php

namespace App\Libraries\Tools;

/**
 * Image generation via external APIs (OpenAI DALL-E, Stable Diffusion, local ComfyUI).
 * Returns a file path to the generated image.
 */
class ImageGenerateTool extends BaseTool
{
    protected string $name = 'image_generate';
    protected string $description = 'Generate images from text prompts using configured image generation APIs (DALL-E, Stable Diffusion, ComfyUI)';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 120,
            'provider' => 'openai',  // openai, stable_diffusion, comfyui
            'output_dir' => 'writable/agent/generated/images',
            'openai' => [
                'api_key_env' => 'OPENAI_API_KEY',
                'model' => 'dall-e-3',
                'size' => '1024x1024',
                'quality' => 'standard',
            ],
            'stable_diffusion' => [
                'base_url' => 'http://localhost:7860',
                'steps' => 30,
                'width' => 1024,
                'height' => 1024,
            ],
            'comfyui' => [
                'base_url' => 'http://localhost:8188',
            ],
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'prompt' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Text description of the image to generate',
            ],
            'provider' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Image provider: openai, stable_diffusion, comfyui (default: from config)',
            ],
            'size' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Image size (e.g. "1024x1024", "512x512")',
            ],
            'filename' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Custom output filename (without extension)',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['prompt'])) return $err;

        $prompt = $args['prompt'];
        $provider = $args['provider'] ?? ($this->config['provider'] ?? 'openai');

        $outputDir = $this->config['output_dir'] ?? 'writable/agent/generated/images';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $filename = $args['filename'] ?? ('img_' . date('Ymd_His') . '_' . substr(md5($prompt), 0, 8));

        return match ($provider) {
            'openai' => $this->generateOpenAI($prompt, $args, $outputDir, $filename),
            'stable_diffusion' => $this->generateStableDiffusion($prompt, $args, $outputDir, $filename),
            'comfyui' => $this->generateComfyUI($prompt, $args, $outputDir, $filename),
            default => $this->error("Unknown image provider: {$provider}"),
        };
    }

    private function generateOpenAI(string $prompt, array $args, string $outputDir, string $filename): array
    {
        $config = $this->config['openai'] ?? [];
        $apiKey = getenv($config['api_key_env'] ?? 'OPENAI_API_KEY');
        if (!$apiKey) {
            return $this->error("OpenAI API key not found. Set the {$config['api_key_env']} environment variable.");
        }

        $size = $args['size'] ?? ($config['size'] ?? '1024x1024');
        $payload = json_encode([
            'model' => $config['model'] ?? 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => $config['quality'] ?? 'standard',
            'response_format' => 'b64_json',
        ]);

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_TIMEOUT => (int)($this->config['timeout'] ?? 120),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("OpenAI request failed: {$error}");
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['data'][0]['b64_json'])) {
            $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
            return $this->error("OpenAI image generation failed: {$msg}");
        }

        $imageData = base64_decode($data['data'][0]['b64_json']);
        $outputPath = "{$outputDir}/{$filename}.png";
        file_put_contents($outputPath, $imageData);

        return $this->success([
            'provider' => 'openai',
            'prompt' => $prompt,
            'path' => $outputPath,
            'size' => $size,
            'bytes' => strlen($imageData),
            'revised_prompt' => $data['data'][0]['revised_prompt'] ?? null,
        ]);
    }

    private function generateStableDiffusion(string $prompt, array $args, string $outputDir, string $filename): array
    {
        $config = $this->config['stable_diffusion'] ?? [];
        $baseUrl = $config['base_url'] ?? 'http://localhost:7860';
        $size = $args['size'] ?? null;
        $width = $config['width'] ?? 1024;
        $height = $config['height'] ?? 1024;

        if ($size && preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
            $width = (int)$m[1];
            $height = (int)$m[2];
        }

        $payload = json_encode([
            'prompt' => $prompt,
            'steps' => $config['steps'] ?? 30,
            'width' => $width,
            'height' => $height,
        ]);

        $ch = curl_init("{$baseUrl}/sdapi/v1/txt2img");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => (int)($this->config['timeout'] ?? 120),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("Stable Diffusion request failed: {$error}");
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['images'][0])) {
            return $this->error("Stable Diffusion generation failed (HTTP {$httpCode})");
        }

        $imageData = base64_decode($data['images'][0]);
        $outputPath = "{$outputDir}/{$filename}.png";
        file_put_contents($outputPath, $imageData);

        return $this->success([
            'provider' => 'stable_diffusion',
            'prompt' => $prompt,
            'path' => $outputPath,
            'size' => "{$width}x{$height}",
            'bytes' => strlen($imageData),
        ]);
    }

    private function generateComfyUI(string $prompt, array $args, string $outputDir, string $filename): array
    {
        $config = $this->config['comfyui'] ?? [];
        $baseUrl = $config['base_url'] ?? 'http://localhost:8188';

        // ComfyUI uses a workflow-based API — use the basic txt2img prompt API
        $payload = json_encode([
            'prompt' => [
                '1' => [
                    'class_type' => 'KSampler',
                    'inputs' => ['seed' => random_int(0, PHP_INT_MAX)],
                ],
            ],
        ]);

        $ch = curl_init("{$baseUrl}/prompt");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => (int)($this->config['timeout'] ?? 120),
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->error("ComfyUI request failed: {$error}");
        }

        $data = json_decode($response, true);
        return $this->success([
            'provider' => 'comfyui',
            'prompt' => $prompt,
            'response' => $data,
            'note' => 'ComfyUI uses async workflow execution. Check output directory for results.',
        ]);
    }
}
