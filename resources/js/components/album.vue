<template>
    <div>
    <div v-if="gettingDataNow">
    <vk-spinner></vk-spinner>
    </div>
    <div v-if="!gettingDataNow">
        <vk-notification position="top-right" status="success" :messages.sync="messages" ></vk-notification>
        <vk-table :data="data" :selected-rows.sync="selection">
            <vk-table-column-select cell="synchronized"></vk-table-column-select>
            <vk-table-column title="Имя" cell="title"></vk-table-column>
        </vk-table>
        <vk-pagination :page.sync="page" :per-page="per_page" :total="total">
            <vk-pagination-page-first></vk-pagination-page-first>
            <vk-pagination-page-prev label="Previous" expanded></vk-pagination-page-prev>
            <vk-pagination-pages></vk-pagination-pages>
            <vk-pagination-page-next label="Next" expanded></vk-pagination-page-next>
            <vk-pagination-page-last></vk-pagination-page-last>
        </vk-pagination>
    </div>
    <p v-vk-margin>
        <vk-button @click="clearAllAlbums">Удалить все товары<br>без удаления подборок</vk-button>
        <vk-button @click="removeSoft">Удалить товары в выбранных<br>подборках без удаления подборок</vk-button>
        <vk-button @click="removeHard">Удалить товары в выбранных<br>подборках с удалением подборок</vk-button>
    </p>
    </div>
</template>

<script>
export default {
    name: 'AlbumsList',
    data() {
        return {
            data: [

            ],
            selection: [],
            page: 1,
            per_page: 10,
            total: 0,
            messages: [],
            gettingDataNow: 0
        }
    },
    mounted() {
        this.loadAlbums();
    },
    watch: {
        page: function () {
            this.loadAlbums();
        },
    },
    methods: {
        loadAlbums: function () {
            let queryString = '/get-albums/'+this.page;
            this.gettingDataNow = true;
            axios
                .get(queryString)
                .then((response) => {
                    let responseData = response.data;
                    this.data = responseData.items;
                    this.total = responseData.count;
                    this.gettingDataNow = false;
                }).catch(error => console.log(error));
        },
        removeSoft: function() {
            let mode = 'soft';
            this.setTask(mode);
        },
        removeHard: function() {
            let mode = 'hard';
            this.setTask(mode);
        },
        setTask: function(mode) {
            if(!this.selection.length) return;

            axios
                .post('/set-task', {
                    'selection': this.selection,
                    'mode': mode,
                })
                .then((response) => {
                    if(response.data.created != undefined) {
                        this.messages.push('Ваша задача успешно отправленна в обработку!');
                    } else {
                        this.messages.push('Задача не была создана. Проверьте правильность выбора или настройки очереди задач!');
                    }
                    this.selection = [];
                }).catch(error => console.log(error));
        },
        clearAllAlbums: function() {
            axios
                .get('/set-delete-all-job')
                .then((response) => {
                    this.messages.push('Ваша задача успешно отправленна в обработку!');
                }).catch(error => console.log(error));
        },
    },
}
</script>
