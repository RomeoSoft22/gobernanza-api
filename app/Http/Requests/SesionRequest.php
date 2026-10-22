<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SesionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sesion = $this->route('sesion');

        return [
            'tipo_reunion_id' => ['required', 'exists:tipos_reunion,id'],
            'numero' => [
                'required', 'integer', 'min:1',
                Rule::unique('sesiones', 'numero')
                    ->where('tipo_reunion_id', $this->input('tipo_reunion_id'))
                    ->whereNull('deleted_at')
                    ->ignore($sesion?->id),
            ],
            'fecha' => ['required', 'date'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'date_format:H:i', 'after:hora_inicio'],
            'modalidad' => ['required', Rule::in(['presencial', 'virtual', 'mixta'])],
            'lugar' => ['nullable', 'string', 'max:200'],
            'estado' => ['required', Rule::in(['programada', 'realizada', 'reprogramada', 'suspendida'])],
            'agenda' => ['nullable', 'string'],
            'acuerdos' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero.unique' => 'Ya existe una sesión con ese número para esta reunión.',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la hora de inicio.',
        ];
    }

    public function attributes(): array
    {
        return [
            'tipo_reunion_id' => 'tipo de reunión',
            'numero' => 'número de sesión',
            'hora_inicio' => 'hora de inicio',
            'hora_fin' => 'hora de fin',
        ];
    }
}
