import { App } from './app.js';

const app = new App(document.getElementById('stage'));
app.start();
window.spring = app;
