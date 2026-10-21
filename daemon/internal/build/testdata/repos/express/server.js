const express = require('express');
const app = express();
app.get('/', (_req, res) => res.send(process.env.GREETING || 'hi'));
app.listen(3000, () => console.log('listening'));
