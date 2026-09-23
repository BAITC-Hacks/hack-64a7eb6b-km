<?php

namespace App\Http\Requests;

use App\Models\GisLayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGisFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $layer = $this->route('layer');

        return $layer instanceof GisLayer && $this->user()?->can('update', $layer) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version_id' => ['required', 'integer', 'min:1'],
            'geometry' => ['required', 'array:type,coordinates'],
            'geometry.type' => ['required', Rule::in(['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'])],
            'geometry.coordinates' => ['required', 'array', 'min:1'],
            'properties' => ['required', 'array', 'max:100'],
            'properties.*' => ['nullable'],
            'properties.title' => ['required', 'string', 'max:200'],
            'properties.description' => ['nullable', 'string', 'max:5000'],
            'properties.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
