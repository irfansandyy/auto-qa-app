<?php

return [
    'required' => ':attribute wajib diisi.',
    'string' => ':attribute harus berupa teks.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'url' => ':attribute harus berupa URL yang valid.',
    'unique' => ':attribute sudah digunakan.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'regex' => 'Format :attribute tidak valid.',
    'in' => 'Nilai :attribute yang dipilih tidak valid.',
    'min' => [
        'string' => ':attribute minimal :min karakter.',
        'numeric' => ':attribute minimal :min.',
        'array' => ':attribute minimal :min item.',
        'file' => ':attribute minimal :min kilobita.',
    ],
    'max' => [
        'string' => ':attribute maksimal :max karakter.',
        'numeric' => ':attribute maksimal :max.',
        'array' => ':attribute maksimal :max item.',
        'file' => ':attribute maksimal :max kilobita.',
    ],
    'attributes' => [
        'name' => 'Nama',
        'email' => 'Alamat email',
        'password' => 'Kata sandi',
        'password_confirmation' => 'Konfirmasi kata sandi',
        'locale' => 'Bahasa',
        'theme' => 'Tampilan',
    ],
];
