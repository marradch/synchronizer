import Vue from 'vue'
import Vuikit from 'vuikit'
import {Table} from "vuikit/lib/table";

Vue.use(Vuikit);
import '@vuikit/theme'

let vue = new Vue({
    el: '#category-selection-app',
    components: {
        VkTable: Table,
    },
    data: {
        data: [

        ],
        selection: [],
        page: 1,
        per_page: 0,
        total: 0,
        sleepSelectionWatcher: 1,
        pageWasChanged: 0,
        areRejectedCategories: 0,
        selectedCount: 0,
    },
    mounted() {
        this.loadCategories();
        this.getSelectedCount();
    },
    watch: {
        page: function () {
            this.loadCategories();
        },
        selection: function (newValue, oldValue) {

            if(this.sleepSelectionWatcher) return;

            if(this.pageWasChanged) {
                this.pageWasChanged = 0;
                return;
            }

            if(this.areRejectedCategories) {
                this.areRejectedCategories = 0;
                return;
            }

            //console.log('to remove');
            //console.log(removeRes);
            let removeRes = oldValue.filter((a)=> newValue.indexOf(a)== -1);
            if(removeRes.length > 0){
                let removeStr = removeRes.join('-');

                axios
                    .get('/set-load-to-vk-no/'+removeStr)
                    .then((response) => {
                        this.getSelectedCount();
                    }).catch(error => {
                        for(var i in removeRes){
                            this.selection.push(removeRes[i]);
                        }
                        console.log(error);
                    });
            }

            //console.log('to add');
            //console.log(addRes);
            let addRes = newValue.filter((a)=> oldValue.indexOf(a)== -1);
            if(addRes.length > 0){
                let addStr = addRes.join('-');
                axios
                    .get('/set-load-to-vk-yes/'+addStr)
                    .then((response) => {
                        if(response.data.length > 0){
                            let responseIds = response.data.map(function (x) {
                                return parseInt(x, 10);
                            });
                            this.selection = this.selection.filter((a)=> responseIds.indexOf(a) == -1);
                            this.areRejectedCategories = 1;
                        }
                        this.getSelectedCount();
                    }).catch(error => {
                        this.selection = this.selection.filter((a)=> addRes.indexOf(a)== -1);
                        console.log(error);
                    });
            }
        }
    },
    methods: {
        loadCategories: function () {
            let queryString = '/get-categories-db';
            if(this.page > 0) {
                queryString += '?page=' + this.page;
            }

            axios
                .get(queryString)
                .then((response) => {
                    let responseData = response.data;
                    this.data = responseData.data;
                    this.per_page = responseData.per_page;
                    this.total = responseData.total;

                    this.sleepSelectionWatcher = 1;

                    this.selection = [];
                    for(let i in this.data) {
                        if(this.data[i].can_load_to_vk == 'yes') {
                            if(this.selection.indexOf(this.data[i].id) < 0)
                            {
                                this.selection.push(this.data[i].id);
                            }
                        }
                    }

                    this.sleepSelectionWatcher = 0;
                    this.pageWasChanged = 1;

                }).catch(error => console.log(error));
        },
        getSelectedCount: function () {
            axios
                .get('/get-selected-count')
                .then((response) => {
                    this.selectedCount = parseInt(response.data.count);
                    console.log(this.selectedCount);
                }).catch(error => console.log(error));
        },
    },
    computed: {
        alertClass: function () {
            return (this.selectedCount >= 100) ? 'alert-danger' : 'alert-primary';
        },
        displayStopHint: function () {
            return (this.selectedCount >= 100) ? true : false;
        },
    },
});

