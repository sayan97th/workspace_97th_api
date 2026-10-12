<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardDocFileRequest extends FormRequest
{
    /**
     * Images, videos, audio and any other attachment dropped into a Doc. Executable
     * and script files are refused since the file is served from the public disk.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:51200',
                'extensions:jpg,jpeg,png,gif,webp,svg,mp4,webm,mov,mp3,wav,ogg,m4a,pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,md,zip,json',
            ],
        ];
    }
}
