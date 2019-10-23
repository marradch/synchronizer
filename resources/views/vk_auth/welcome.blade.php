@extends('layouts.auth')

@section('title', 'Добро пожаловать!')

@section('content')
    <h1>Добро пожаловать на страницу синхронизатора товаров ВКонтакте</h1>
    <a class="btn btn-primary" href="{{ $loginUrl }}">Авторизация через ВКонтакте</a>
@endsection


