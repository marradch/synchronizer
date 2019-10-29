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
        total: 0
    },
    mounted() {
        this.loadCategories();
    },
    watch: {
        page: function () {
            this.loadCategories();
        },
        selection: function (newValue, oldValue) {

            let removeRes = oldValue.filter((a)=> newValue.indexOf(a)== -1);
            //console.log('to remove');
            //console.log(removeRes);
            if(removeRes.length > 0){
                axios
                    .get('/set-load-to-vk-no/'+removeRes[0])
                    .then((response) => {

                    }).catch(error => {
                    this.selection.push(removeRes[0]);
                        console.log(error);
                    });
            }

            let addRes = newValue.filter((a)=> oldValue.indexOf(a)== -1);
            if(addRes.length > 0){
                axios
                    .get('/set-load-to-vk-yes/'+addRes[0])
                    .then((response) => {

                    }).catch(error => {
                    this.selection.filter(function(value, index, arr){
                        return value != addRes[0];
                    });
                    console.log(error);
                });
            }
            //console.log('to add');
            //console.log(addRes);
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
                    for(let i in this.data) {
                        if(this.data[i].can_load_to_vk == 'yes') {
                            if(this.selection.indexOf(this.data[i].id) < 0)
                            {
                                this.selection.push(this.data[i].id);
                            }
                        }
                    }
                }).catch(error => console.log(error));
        }
    }
});

