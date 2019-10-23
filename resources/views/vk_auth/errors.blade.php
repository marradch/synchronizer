@extends('layouts.auth')

@section('title', 'Ошибка авторизации')

@section('content')
    <div class="alert alert-danger" role="alert">
        {{ $errorText }}
    </div>
    <a href="{{ route('home') }}"><- Назад</a>
@endsection
