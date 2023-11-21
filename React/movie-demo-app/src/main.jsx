import React from 'react';
// import ReactDOM from 'react-dom';  //pre React v 18
// helod
import { createRoot } from 'react-dom/client';
import App from './App';

// ReactDom.render(<App />, document.getElementById('root')); // pre React v 18
const root = createRoot(document.getElementById('root'));

root.render(<App />);
