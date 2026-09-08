<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Slate\Source\SourceApiErrorReason;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

final class StoreSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'upload' => ['bail', 'required_without:url', 'array:content_type,size'],
            'upload.content_type' => ['required_with:upload', 'string', 'max:255'],
            'upload.size' => ['required_with:upload', 'integer', 'min:1'],
            'url' => ['bail', 'required_without:upload', 'string', 'url:https', 'max:2048'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['upload', 'url']) !== []) {
                $validator->errors()->add('payload', 'The source payload contains unsupported fields.');
            }

            if ($this->has('upload') === $this->has('url')) {
                $validator->errors()->add('payload', 'Submit exactly one source upload or URL.');
            }
        }];
    }

    public function isUpload(): bool
    {
        return $this->has('upload');
    }

    public function contentType(): string
    {
        return $this->string('upload.content_type')->toString();
    }

    public function bytes(): int
    {
        return $this->integer('upload.size');
    }

    public function sourceUrl(): string
    {
        return $this->string('url')->toString();
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The submitted source is invalid.',
            'reason' => SourceApiErrorReason::InvalidSource->value,
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
