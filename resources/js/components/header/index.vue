<template>
  <div class="header-wrapper d-flex justify-content-between align-items-baseline">
    <!-- <SearchBar /> -->
    <Logo />
    <div class="center-header" ref="searchContainer">
      <div
        :class="['faq-form faq-form-btn custom-search', { 'search-result-open': ((autocomplete?.length || searchFromTitle?.length || searchHistory.length || searchResults) && search_focus) }, { 'search-result-open': search_focus || noFoundsearchResults }, { 'search-result-open': AiResultLoader }]">

        <input class="form-control form-control-lg" @focus="FocusIn" @blur="FocusOut" v-model="searchTerm"
          @keydown.enter="fetchResults($event)" @input="fetchResults($event)"
          v-bind:class="{ 'search-result-open': searchResults.length > 0 || noFoundsearchResults }" type="text"
          placeholder="Search.." />
        <vue-feather class="search-icon" type="search"></vue-feather>
        <vue-feather v-if="$can('manage', 'Auto Documentation') && searchTerm" class="clear-icon me-4 f-12 text-primary create_article_new" title="create a new article from AI search" type="file-plus" @click="createArticleSearch"></vue-feather>
        <vue-feather v-if="searchTerm" class="clear-icon" type="x" @click="clearSearch"></vue-feather>

        <div v-if="createArticleloder">
            <button class="btn loding-search-left">
                <div class="loader-custom"></div>
            </button>
        </div>
        <div v-if="loading || AiResultLoader">
            <button class="btn loding-search">
                <div class="loader-custom"></div>
            </button>
        </div>

        <div class="search_ul overlap-main-search simplebar-content-wrapper px-3">
          <div class="inner-scroll">
            <div class="latest-queries d-block w-100 mb-1" v-if="searchFromTitle?.length && PressedEnter == true">
              <h6 class="txt-gray f-14 m-0">Recent</h6>
            </div>
            <ul v-if="searchHistory.length && PressedEnter == true">
              <li
                class="search_li search-gray-box px-0 d-flex align-items-center mb-0 py-2 justify-content-between hover-close"
                v-for="(result, index) in searchHistory" :key="index">
                <span class="d-flex align-items-center ">
                  <vue-feather class="w-18 me-2 text-gray" type="clock" name="clock"></vue-feather>
                  <span class="realname" @click.prevent="optHistory(result.search_term)">
                    <div class="search_di justify-content-between">
                      <span class="d-flex align-items-center">
                        <span class="search_spantext text-dark">
                          <h6 class="mb-0 f-14 f-w-500" v-html="highlightSearchTerms(result.search_term)"></h6>
                        </span>
                      </span>
                    </div>
                  </span>
                </span>
                <a href="javascript:void(0);" @click.prevent="delHistory(result.id)"
                  class="m-0 font-small btn-close"></a>
              </li>
            </ul>

            <hr class="mb-3 mt-2 light-border" v-if="searchHistory?.length && PressedEnter == true">
            <div class="latest-queries d-block w-100 mb-1" v-if="searchFromTitle?.length && PressedEnter == true">
              <h6 class="txt-gray f-14 m-0">Suggestion</h6>
            </div>
            <ul v-if="searchFromTitle?.length && PressedEnter == true">
              <li class="search_li search-gray-box px-0 mb-0" v-for="(result, index) in searchFromTitle" :key="index">

                <span class="realname" @click="openFile(result.encrypt_id)">
                  <div class="search_di justify-content-between">
                    <span class="d-flex align-items-center">
                      <div class="file-icon me-2">
                        <img class="w-24" src="/resources/js/assets/images/document.svg">
                      </div>
                      <span class="search_spantext text-dark">
                        <h6 class="mb-0 f-14 f-w-500" v-html="highlightSearchTerms(result.title)"></h6>
                        <p class="m-0 text-muted font-small">
                            <span class="doted">
                              {{ result.status === 'pending_approval' ? 'In Review' : result.status }}
                            </span>
                            <span class="doted">{{formattedDateWithoutYear(result.created_at)}}</span><span class="">{{ timeAgo(result.updated_at) }}</span></p>
                      </span>
                    </span>

                    <div class="article-control">
                      <!-- Copy Button -->
                      <span class="me-3 text-gray cursor-pointer prevent-close pt-2 position-relative"
                        @click.prevent="copyToClipboard(result.encrypt_id, $event)">
                        <vue-feather type="link" title="Copy Article Link" class="w-18"></vue-feather>
                        <span class="copied-text" v-if="isCopied[result.encrypt_id]">Copied !</span>
                      </span>
                      <span class="me-0 cursor-pointer btn btn-primary px-2 py-1 icon-size-18"
                        @click="openFile(result.encrypt_id)">
                        <vue-feather type="chevron-right" title="" class="pt-2px"></vue-feather>
                      </span>

                    </div> <!-- Show "Copied" only for the clicked item -->
                  </div>
                </span>

              </li>

            </ul>

            <hr class="mb-3 mt-2 light-border" v-if="searchFromTitle?.length && PressedEnter == true">
            <ul v-if="autocomplete?.length && PressedEnter == true">
              <li class="search_li  search-gray-box px-0 mb-0 py-2" v-for="(result, index) in autocomplete"
                :key="index">
                <span class="realname" @click.prevent="optHistory(result)">
                  <div class="search_di justify-content-between">
                    <span class="d-flex align-items-center">

                      <vue-feather class="w-18 me-2 text-gray" type="search" name="search"></vue-feather>
                      <span class="search_spantext text-dark">
                        <h6 class="mb-0 f-w-500" v-html="highlightSearchTerms(result)"></h6>
                        <p class="m-0 text-muted font-small"></p>
                      </span>
                    </span>
                  </div>
                </span>
              </li>
            </ul>
          </div>
          <hr class="mb-3 mt-2 light-border" v-if="autocomplete?.length && PressedEnter == true">
          <div class="suggestions d-block w-100 mb-3" v-if="searchResults.length || noFoundsearchResults">
            <img width="20" class="img-fluid me-2" src="../../assets/images/svg-icon/suggestion.svg" alt />
            <span class="txt-light-dark">Assistant</span>
          </div>
          <div class="search-gray-box w-100 d-block me-0" v-if="searchResults.length"
            v-for="(result, index) in searchResults" :key="index">
            <p class="search_spantext lh-base m-0" v-html="replceTextWithBr(result.highlights)"></p>
            <div class="files-link mt-2 d-inline-block me-2" v-for="(source) in result.source">
              <a href="javascript:void(0);" @click.prevent="openFile(source.file_encrypt_id)"
                class="file-name bg-white py-2 mb-2 px-3 rounded text-muted d-inline-block ">
                <img width="18" class="img-fluid me-1" src="../../assets/images/svg-icon/file.svg" alt />{{
                source.title }}</a>
            </div>
          </div>
          <div class="search-gray-box w-100 d-block me-0" v-if="noFoundsearchResults">
            <p class="search_spantext lh-base m-0"> It looks like there isn't any information on this topic… </p>
          </div>



          <!-- <div class="ai-loader" v-if="AiResultLoader">
            <div class="inner one"></div>
            <div class="inner two"></div>
            <div class="inner three"></div>
          </div> -->


          <!-- From Uiverse.io by Admin12121 -->
          <!-- <div class="ai-icon" v-if="AiResultLoader">
            <svg class="svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path fill-rule="evenodd" clip-rule="evenodd"
                d="M11.5 6C11.3949 6.00006 11.2925 5.96705 11.2073 5.90565C11.1221 5.84425 11.0583 5.75758 11.0251 5.65792L10.7623 4.86908C10.6623 4.57101 10.4288 4.33629 10.13 4.23693L9.34102 3.97354C9.24166 3.94019 9.1553 3.87649 9.09411 3.79142C9.03292 3.70635 9 3.60421 9 3.49943C9 3.39465 9.03292 3.29252 9.09411 3.20745C9.1553 3.12238 9.24166 3.05867 9.34102 3.02532L10.13 2.76193C10.4282 2.66191 10.663 2.42852 10.7623 2.12979L11.0258 1.34094C11.0591 1.24161 11.1229 1.15526 11.2079 1.09409C11.293 1.03291 11.3952 1 11.5 1C11.6048 1 11.707 1.03291 11.7921 1.09409C11.8771 1.15526 11.9409 1.24161 11.9742 1.34094L12.2377 2.12979C12.2868 2.27697 12.3695 2.4107 12.4792 2.52041C12.589 2.63013 12.7227 2.71281 12.87 2.76193L13.659 3.02532C13.7583 3.05867 13.8447 3.12238 13.9059 3.20745C13.9671 3.29252 14 3.39465 14 3.49943C14 3.60421 13.9671 3.70635 13.9059 3.79142C13.8447 3.87649 13.7583 3.94019 13.659 3.97354L12.87 4.23693C12.5718 4.33696 12.337 4.57034 12.2377 4.86908L11.9742 5.65792C11.9411 5.75747 11.8774 5.84406 11.7923 5.90545C11.7072 5.96684 11.6049 5.99992 11.5 6Z"
                fill="currentColor"></path>
              <path fill-rule="evenodd" clip-rule="evenodd"
                d="M6 13C5.85133 13.0001 5.7069 12.9504 5.58969 12.859C5.47247 12.7675 5.38921 12.6395 5.35313 12.4952L5.12388 11.5745C4.91418 10.7391 4.26198 10.0868 3.42674 9.87703L2.50619 9.64774C2.36169 9.61194 2.23333 9.52878 2.14159 9.41151C2.04985 9.29425 2 9.14964 2 9.00075C2 8.85185 2.04985 8.70724 2.14159 8.58998C2.23333 8.47272 2.36169 8.38955 2.50619 8.35376L3.42674 8.12446C4.26198 7.91473 4.91418 7.2624 5.12388 6.427L5.35313 5.50629C5.38892 5.36176 5.47207 5.23338 5.58931 5.14162C5.70655 5.04986 5.85113 5 6 5C6.14887 5 6.29345 5.04986 6.41069 5.14162C6.52793 5.23338 6.61108 5.36176 6.64687 5.50629L6.87612 6.427C6.97865 6.83721 7.19071 7.21184 7.48965 7.51082C7.78858 7.80981 8.16313 8.02192 8.57326 8.12446L9.49381 8.35376C9.63831 8.38955 9.76667 8.47272 9.85841 8.58998C9.95015 8.70724 10 8.85185 10 9.00075C10 9.14964 9.95015 9.29425 9.85841 9.41151C9.76667 9.52878 9.63831 9.61194 9.49381 9.64774L8.57326 9.87703C8.16313 9.97957 7.78858 10.1917 7.48965 10.4907C7.19071 10.7897 6.97865 11.1643 6.87612 11.5745L6.64687 12.4952C6.61079 12.6395 6.52753 12.7675 6.41031 12.859C6.2931 12.9504 6.14867 13.0001 6 13Z"
                fill="currentColor"></path>
              <path fill-rule="evenodd" clip-rule="evenodd"
                d="M13.5005 23C13.3376 23 13.1791 22.9469 13.049 22.8487C12.9189 22.7505 12.8243 22.6127 12.7795 22.456L11.9665 19.61C11.7915 18.9971 11.4631 18.4389 11.0124 17.9882C10.5616 17.5374 10.0035 17.209 9.39054 17.034L6.54454 16.221C6.38795 16.1761 6.25021 16.0815 6.15216 15.9514C6.05411 15.8214 6.00108 15.6629 6.00108 15.5C6.00108 15.3371 6.05411 15.1786 6.15216 15.0486C6.25021 14.9185 6.38795 14.8239 6.54454 14.779L9.39054 13.966C10.0035 13.791 10.5616 13.4626 11.0124 13.0118C11.4631 12.5611 11.7915 12.0029 11.9665 11.39L12.7795 8.544C12.8244 8.38741 12.919 8.24967 13.0491 8.15162C13.1792 8.05357 13.3376 8.00054 13.5005 8.00054C13.6634 8.00054 13.8219 8.05357 13.952 8.15162C14.0821 8.24967 14.1767 8.38741 14.2215 8.544L15.0345 11.39C15.2096 12.0029 15.538 12.5611 15.9887 13.0118C16.4394 13.4626 16.9976 13.791 17.6105 13.966L20.4565 14.779C20.6131 14.8239 20.7509 14.9185 20.8489 15.0486C20.947 15.1786 21 15.3371 21 15.5C21 15.6629 20.947 15.8214 20.8489 15.9514C20.7509 16.0815 20.6131 16.1761 20.4565 16.221L17.6105 17.034C16.9976 17.209 16.4394 17.5374 15.9887 17.9882C15.538 18.4389 15.2096 18.9971 15.0345 19.61L14.2215 22.456C14.1768 22.6127 14.0822 22.7505 13.9521 22.8487C13.822 22.9469 13.6635 23 13.5005 23Z"
                fill="currentColor"></path>
            </svg>
          </div> -->
          <!-- <div class="ai-loader" v-if="AiResultLoader">
            <video class="w-100 h-100 " autoplay src="/resources/images/ai-loader.mp4"></video>
          </div> -->


          <div class="enter text-small mb-1 d-inline-block text-muted" v-if="PressedEnter">
            <img width="25" class="img-fluid me-3" src="../../assets/images/svg-icon/enter.svg" alt />Enter for search
          </div>

        </div>
      </div>
    </div>
    <div class="nav-right">
      <ul class="nav-menus">
        <Notifications />
        <HeaderMenu />
        <Profile />
      </ul>
    </div>
  </div>
</template>
<style>
.highlight {
  background-color: #bad3fc ;
  /* Adjust styling for highlighted text */
}
</style>
<script>
import { mapState } from 'vuex';
import { HTTP } from '@/services/http-common';
import Bookmark from '../bookmark.vue';
import { Swiper, SwiperSlide } from "swiper/vue";
import { Autoplay } from "swiper";
import "swiper/css";
import HeaderMenu from './headermenu.vue';
import Notifications from './notifications.vue';
import Mode from './mode.vue';
import Profile from './profile.vue';
import { useToast } from 'vue-toastification';
import Logo from './logo.vue';
import SearchBar from './search.vue';
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { debounce } from '@/plugins/debounce.js';
import { useRoute, useRouter } from 'vue-router';
import { useArticleStore } from '@/stores/article.js';


export default {
  //   name: 'Search',
  components: {
    Bookmark,
    Notifications,
    Mode,
    HeaderMenu,
    //Cart,
    Profile,
    Logo,
    Swiper,
    SwiperSlide,
    SearchBar,
  },
  data() {
    return {
      bookmark: false,
      headerMenu1: false,

    };
  },
  computed: {
    ...mapState({
      menuItems: (state) => state.menu.searchData,
      megamenuItems: (state) => state.menu.megamenu,
      searchOpen: (state) => state.menu.searchOpen,
      headerMenu: (state) => state.menu.headerMenu,
    }),
  },

  beforeUnmount() {
    document.removeEventListener("mousedown", this.handleClickOutside);
  },
  methods: {
    search_open() {
      this.$store.state.menu.searchOpen = true;
    },

    bookmark_open() {
      this.bookmark = !this.bookmark;
    },
    menu_open() {
      this.headerMenu1 = !this.headerMenu;
    },
  },
  setup() {

    const searchTerm = ref('');
    const router = useRouter();
    const frontbaseurl = import.meta.env.VITE_APP_BASE_URL;
    const searchResults = ref([]);
    const searchHistory = ref([]);
    const toast = useToast();
    const searchFromTitle = ref([]);
    const autocomplete = ref([]);
    const AIResults = ref('');
    const loading = ref(false);
    const search_focus = ref(false);
    const searchContainer = ref(null);
    const searchedFiles = ref([]);
    const error = ref(null);
    const PressedEnter = ref(true);
    const createArticleBtn = ref(false);
    const createArticleloder = ref(false);
    const AiResultLoader = ref(false);
    const noFoundsearchResults = ref(false);
    const isCopied = ref({});
    const articleStore = useArticleStore();

    const fetchResults = debounce(async (event) => {
      if (searchTerm.value != '') {
        if (event && event.key === 'Enter') {
          PressedEnter.value = false;
          searchHistory.value = [];
          searchFromTitle.value = [];
          autocomplete.value = [];
          searchGlobal();
          setTimeout(() => {
            HTTP.get(`elastic-search/search-save?search=${searchTerm.value}`);
          }, 100)
        } else {
          PressedEnter.value = true;
          searchResults.value = [];
          getSearchHistory();
          searchMatchedTitle();
          getAutoCompleteSentence();
        }
        createArticleBtn.value = true;
      } else {
        await getSearchHistory();
      }
    }, 500);

    const getSearchHistory = async () => {
      try {
        const response = await HTTP.get(`elastic-search/search-history/?search=${searchTerm.value}`);
        if (response.data.status) {
          searchHistory.value = response.data.data;
          searchFromTitle.value = response.data.files_history;
        } else {
          searchHistory.value = [];
        }
      } catch (err) {
        console.error("Error fetching data:", err);
      }
    };

    const getSearchableFiles = async () => {
      try {
        const response = await HTTP.get(`elastic-search/search-files-id/`);
        if (response.data.status) {
          searchedFiles.value = response.data.files_id;

        } else {
          searchedFiles.value = [];
        }
      } catch (err) {
        console.error("Error fetching data:", err);
      }
    };

    const FocusIn = () => {
      try {
        if (!search_focus.value) {
          search_focus.value = true;
          noFoundsearchResults.value = false
          getSearchHistory()
        }
      } catch (err) {
        console.error("Error fetching data:", err);
      }
    };

    const FocusOut = () => {
      try {
        setTimeout(() => {
        }, 200);
      } catch (err) {
        console.error("Error fetching data:", err);
      }
    };

    const optHistory = (text) => {
      try {
        searchTerm.value = text;
        searchGlobal()

      } catch (err) {
        console.error("Error fetching data:", err);
      }
    };

    const delHistory = async (historyId) => {
      try {
        const response = await HTTP.delete(`elastic-search/delete-history/${historyId}`);
        console.log(response);
        if (response.data.status) {
          getSearchHistory()
        }
      } catch (err) {
        console.error("Error fetching data:", err);
      }
    };


    const searchMatchedTitle = debounce(async () => {
      if (searchTerm.value != '') {
        try {

          error.value = null;
          const data = {
            'searchitem': searchTerm.value,
            'searchedFiles': searchedFiles.value
          }
          const response = await HTTP.post(`elastic-search/search-with-title`, data);
          if (response.data.status) {
            searchFromTitle.value = response.data.data;
          } else {
            searchFromTitle.value = [];
          }
        } catch (err) {
          console.error("Error fetching data:", err);
          error.value = "Error fetching data";
        } finally {
        }
      } else {
        searchFromTitle.value = [];
      }
    }, 500);


    const searchGlobal = debounce(async () => {
      if (searchTerm.value != '') {
        try {
          PressedEnter.value = false;
          AiResultLoader.value = true;
          noFoundsearchResults.value = false;
          searchResults.value = [];
          loading.value = true;
          error.value = null;
          const data = {
            'searchitem': searchTerm.value,
            'searchedFiles': searchedFiles.value
          }
          const response = await HTTP.post(`elastic-search/search`, data);
          if (response.data.search.length === 0) {
            searchResults.value = [];
            noFoundsearchResults.value = true;
            searchHistory.value = [];
            searchFromTitle.value = [];
            autocomplete.value = [];

          } else {
            searchResults.value = response.data.search;
            searchHistory.value = [];
            searchFromTitle.value = [];
            autocomplete.value = [];

          }
        } catch (err) {
          console.error("Error fetching data:", err);
          //error.value = "Error fetching data";
        } finally {
          loading.value = false;
          AiResultLoader.value = false;
        }
      }
    }, 5);

    const timeAgo = (dateString) => {
      const date = new Date(dateString);
      const diff = Math.floor((new Date() - date) / 1000); // Difference in seconds

      if (diff < 60) return `Edited  ${diff} sec ago`;
      if (diff < 3600) return `Edited ${Math.floor(diff / 60)} min ago`;
      if (diff < 86400) return `Edited ${Math.floor(diff / 3600)} hours ago`;
      return `Edited ${Math.floor(diff / 86400)} days ago`;
    };

    const createArticleSearch = async () => {
        try {
            createArticleloder.value = true;
            const data = {
                'searchitem': searchTerm.value,
                'create_article': 'yes'
            }
          const response = await HTTP.post(`elastic-search/search`, data);
          createArticleloder.value = false;
          if (response.data.article_id != 0) {
            toast.success('Article created successfully');
            setTimeout(() => {
                const toastBodies = document.querySelectorAll('.Vue-Toastification__toast-body');
                const lastToastBody = toastBodies[toastBodies.length - 1];
                if (lastToastBody) {
                    const link = document.createElement('a');
                    link.href = `/article/${response.data.article_id}`;
                    link.textContent = 'Click here to view the article';
                    link.style.color = '#007bff';
                    link.style.display = 'block';
                    link.style.marginTop = '2px';

                    lastToastBody.appendChild(link);
                }
            }, 500); // small delay to ensure the toast is in DOM

          } else {
            toast.success('No relevant content found to create an article');
          }
        } catch (err) {
            console.error("Error fetching data:", err);
            error.value = "Error fetching data";
        } finally {
        };
    };

    const formattedDateWithoutYear = (dateString) => {
        if (!dateString) return "";
        const date = new Date(dateString);
        const now = new Date();
        const options = { month: "short", day: "numeric" };
        return date.getFullYear() === now.getFullYear()
            ? date.toLocaleDateString("en-US", options)
            : date.toLocaleDateString("en-US", { ...options, year: "numeric" });
    };
    const getAutoCompleteSentence = debounce(async () => {
      if (searchTerm.value != '') {
        try {
          const response = await HTTP.get(`elastic-search/autocomplete-sentence/?search=${searchTerm.value}`);
          if (response.data.status) {
            autocomplete.value = response.data.autocomplete;

          } else {
            autocomplete.value = [];
          }
        } catch (err) {
          console.error("Error fetching data:", err);
        } finally {
        }
      } else {
        autocomplete.value = [];
      }
    }, 500);

    const replceTextWithBr = (content) => {
      try {
        const data = content.replace(/\n/g, '<br>');
        return data;
      } catch (err) {

      }
    };

    const clearSearch = () => {
      searchTerm.value = '';
      searchedFiles.value = [];
      searchResults.value = [];
      autocomplete.value = [];
      PressedEnter.value = true;
      AiResultLoader.value = false;
      search_focus.value = false;
      noFoundsearchResults.value = false;
      createArticleBtn.value = false;


    };


    const copyToClipboard = (encryptID, event) => {
      event.stopPropagation(); // Prevents triggering openFile()

      const url = window.location.origin + `/article/${encryptID}`;
      navigator.clipboard.writeText(url)
        .then(() => {
          // Set copied state for the specific item
          isCopied.value = { ...isCopied.value, [encryptID]: true };

          setTimeout(() => {
            // Reset only the specific item
            const updatedCopied = { ...isCopied.value };
            delete updatedCopied[encryptID];
            isCopied.value = updatedCopied;
          }, 1500);
        })
        .catch(err => {
          console.error("Failed to copy: ", err);
        });
    };



    const searchSave = async () => {
      try {
        if (searchTerm.value !== '') {
          PressedEnter.value = false;
          searchHistory.value = [];
          searchFromTitle.value = [];
          autocomplete.value = [];
          await searchGlobal();
          await HTTP.get(`elastic-search/search-save?search=${searchTerm.value}`);
        }
      } catch (err) {
      }
    };

    const highlightSearchTerms = (text) => {
      if (!searchTerm.value) {
        return text;
      }
      const regex = new RegExp(searchTerm.value, 'gi');
      return text.replace(regex, match => `<span class="highlight">${match}</span>`);
    };

    const filelink = (text) => {
      if (text.type == 'GoogleDrive') {
        return text.link
      } else {
        return '/article/' + text.file_encrypt_id;
      }
    }

    const openFile = (encrypt_id) => {
      clearSearch();
      setTimeout(() => {
        articleStore.setSearchedArticle(true);
        router.replace(`/article/${encrypt_id}`);
      }, 200);
    }

    const handleClickOutside = (event) => {
      if (!searchContainer.value) return;

      if (event.target.classList.contains('feather-link')) {
        return false;
      }
      if (!searchContainer.value.contains(event.target)) {
        clearSearch();
      }
    };


    onMounted(() => {
      document.addEventListener("mousedown", handleClickOutside);

      setTimeout(() => {
        getSearchHistory()
      }, 6000)
    });

    return {
      filelink,
      openFile,
      searchTerm,
      searchResults,
      modules: [Autoplay],
      fetchResults,
      highlightSearchTerms,
      searchFromTitle,
      noFoundsearchResults,
      autocomplete,
      searchHistory,
      clearSearch,
      search_focus,
      copyToClipboard,
      isCopied,
      searchedFiles,
      replceTextWithBr,
      createArticleSearch,
      createArticleBtn,
      optHistory,
      formattedDateWithoutYear,
      timeAgo,
      delHistory,
      AiResultLoader,
      frontbaseurl,
      searchSave,
      FocusOut,
      createArticleloder,
      searchContainer,
      // AIResults,
      FocusIn,
      PressedEnter,
      loading,
      error
    };


  },
};
</script>
<style>
/* Search Results */
.search_ul {
  list-style-type: none;
  padding: 0;
  margin-top: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  background: #fff;
  max-height: 300px;
  overflow-y: auto;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.search_li {
  padding: 10px;
  border-bottom: 1px solid #ddd;
}

.search_li:last-child {
  border-bottom: none;
}

.search_di {
  display: flex;
  align-items: center;
}

.search_di .fa-file-text-o {
  margin-right: 10px;
}

.search_spantext em {
  background-color: yellow;
  font-weight: bold;
}

/* Responsive Design */
@media (max-width: 768px) {
  .faq-form .form-control {
    width: 100%;
  }

  .faq-form .form-control-lg {
    height: 40px;
    padding: 8px;
  }

  .faq-form .search-icon {
    right: 10px;
    top: 10px;
  }

  .search_li {
    padding: 8px;
    font-size: 14px;
  }

  .search_di .fa-file-text-o {
    margin-right: 8px;
  }
}

.clear-icon {
  position: absolute;
  right: 10px;
  cursor: pointer;
  top: 8px;
}

.search-result-open .clear-icon {
  right: 40px;
  /* Adjust this if there's overlap with the search icon */
}
</style>
