@extends('layouts.main')

@section('title', 'Дашборд')

@section('content')
    <h1>Категории из файла</h1>
    <div id="app">
        <categories-list></categories-list>
        <script src="/js/app.js?v=1.0"></script>
    </div>
@endsection
