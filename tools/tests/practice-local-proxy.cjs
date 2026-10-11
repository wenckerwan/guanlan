// Local, disposable integration gateway. The API is reached through an SSH tunnel.
const http = require('node:http');
http.createServer((request, response) => {
  const upstream = http.request({hostname:'127.0.0.1',port:request.url.startsWith('/api/v1/')?19511:3212,path:request.url,method:request.method,headers:request.headers}, incoming => {
    response.writeHead(incoming.statusCode,incoming.headers); incoming.pipe(response);
  });
  upstream.on('error',()=>{response.writeHead(502);response.end('Isolated test upstream unavailable');});
  request.pipe(upstream);
}).listen(3211,'127.0.0.1',()=>console.log('ISOLATED_GATEWAY_READY'));
