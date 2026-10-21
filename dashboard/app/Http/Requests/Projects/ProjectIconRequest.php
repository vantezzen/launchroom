<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Support\Images\IconSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * An uploaded project icon. Only the size is checked here; the format is
 * decided from the bytes by IconSanitizer (never by name or MIME type).
 */
final class ProjectIconRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'icon' => ['required', 'file', 'max:'.intdiv(IconSanitizer::MAX_BYTES, 1024)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'icon.max' => 'The image must be smaller than 256 KB.',
        ];
    }

    public function iconBytes(): string
    {
        $file = $this->file('icon');

        return $file instanceof UploadedFile ? $file->getContent() : '';
    }
}
