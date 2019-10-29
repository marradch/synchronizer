@extends('layouts.main')

@section('title', 'Дашборд')

@section('content')
    <h1>Категории из файла</h1>
    <div id="category-selection-app">
        <vk-table :data="data" :selected-rows.sync="selection">
            <vk-table-column-select cell="synchronized"></vk-table-column-select>
            <vk-table-column title="Name" cell="name"></vk-table-column>
        </vk-table>
        <vk-pagination :page.sync="page" :per-page="per_page" :total="total">
            <vk-pagination-page-first></vk-pagination-page-first>
            <vk-pagination-page-prev label="Previous" expanded></vk-pagination-page-prev>
            <vk-pagination-pages></vk-pagination-pages>
            <vk-pagination-page-next label="Next" expanded></vk-pagination-page-next>
            <vk-pagination-page-last></vk-pagination-page-last>
        </vk-pagination>
        <script src="/js/app.js?v=1.0"></script>
    </div>
@endsection
