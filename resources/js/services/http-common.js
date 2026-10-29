import axios from 'axios';
import { useToast } from 'vue-toastification';
import router from '@/router';
import { getExpiration } from '@/utils/storage';
const toast = useToast();

export const HTTP = axios.create({
  baseURL: `${import.meta.env.VITE_APP_BASE_URL}/api/`,
  //withCredentials: true,
  headers: {
    'Content-Type': 'application/json'
  }
})

HTTP.interceptors.request.use(function (config) {

  const userToken = localStorage.getItem("userToken");
  const domain = localStorage.getItem("domain");
  if (domain) {
    const token = getExpiration('token_expiry');
    if (!token) {
     // localStorage.setItem('websiteaccessable', 'yes')
      router.push({ name: 'Login' });
      //return Promise.reject(new Error('Token expired, redirecting to login'));
    }
    const url = import.meta.env.VITE_APP_BASE_URL.split('');
    const index = url.indexOf('/');
    url.splice(index + 2, 0, `${domain}.`);
    const newUrl = url.join('');
    config.baseURL = `${newUrl}/api/`;
  }
  if (userToken) {
    config.headers["Authorization"] = "Bearer " + userToken;
  } else {
    console.warn('User token not found');
  }

  return config;
}, function (error) {
  // Handle errors during the request
  return Promise.reject(error);
});

// Response interceptor
let isToastShown = false; // Flag to prevent multiple toasts
HTTP.interceptors.response.use(
  response => {
    isToastShown = false; // Reset the flag on a successful response
    return response;
  },
  error => {
    if (error.response && error.response.status === 401) {
      if (!isToastShown) {
        toast.error("Your login session has expired. Please log in again!");
        isToastShown = true; // Set flag to true after showing the toast
      }
      localStorage.setItem('websiteaccessable', 'yes')
      window.localStorage.removeItem('userData');
      window.localStorage.removeItem('userRole');
      window.localStorage.removeItem('userToken');
      window.localStorage.removeItem('token_expiry');
      window.localStorage.removeItem('companyData');
      window.localStorage.removeItem('domain');
      window.localStorage.removeItem('timezones');
      window.localStorage.removeItem('permission');
      router.push(`/login`);
    }
    return Promise.reject(error);
  }
);
