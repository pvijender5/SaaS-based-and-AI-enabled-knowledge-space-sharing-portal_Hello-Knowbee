const WebSocket = require('ws');
const http = require('http');
const setupWSConnection = require('y-websocket/bin/utils').setupWSConnection;

const PORT = process.env.PORT || 1234;

const server = http.createServer((request, response) => {
  response.writeHead(200, { 
    'Content-Type': 'text/plain',
    'Access-Control-Allow-Origin': '*', 
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Origin, Content-Type, Accept'
  });
  response.end('Yjs WebSocket Server\n');
});

const wss = new WebSocket.Server({ server });

wss.on('connection', (conn, req) => {
  console.log('Client connected');
  setupWSConnection(conn, req);

  conn.on('close', () => console.log('Client disconnected'));
  conn.on('error', (err) => console.error('WebSocket error:', err));
});

server.listen(PORT, () => {
  console.log(`Yjs WebSocket server running on port ${PORT}`);
});
