// Simple STL preview (binary and ASCII) using the locally hosted three.js. No data leaves the server.
(function () {
  var box = document.getElementById('stl'); if (!box || !window.THREE) { return; }
  var msg = document.getElementById('stl-msg'), info = document.getElementById('stl-info');
  function parse(buf) {
    var dv = new DataView(buf), tris = dv.getUint32(80, true), binary = 84 + tris * 50 === buf.byteLength, pos = [];
    if (binary) { for (var i = 0; i < tris; i++) { var o = 84 + i * 50 + 12; for (var v = 0; v < 9; v++) { pos.push(dv.getFloat32(o + v * 4, true)); } } }
    else {
      var txt = new TextDecoder().decode(buf), re = /vertex\s+(\S+)\s+(\S+)\s+(\S+)/g, m;
      while ((m = re.exec(txt))) { pos.push(parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3])); }
    }
    return new Float32Array(pos);
  }
  fetch(box.getAttribute('data-src'), { credentials: 'same-origin' }).then(function (r) { if (!r.ok) { throw new Error('download failed'); } return r.arrayBuffer(); }).then(function (buf) {
    var pos = parse(buf); if (!pos.length) { throw new Error('no triangles found'); }
    var g = new THREE.BufferGeometry(); g.setAttribute('position', new THREE.BufferAttribute(pos, 3)); g.computeVertexNormals(); g.computeBoundingBox();
    var bb = g.boundingBox, size = new THREE.Vector3(); bb.getSize(size); var c = new THREE.Vector3(); bb.getCenter(c); g.translate(-c.x, -c.y, -c.z);
    var w = box.clientWidth, h = box.clientHeight, scene = new THREE.Scene(), cam = new THREE.PerspectiveCamera(40, w / h, 0.1, 100000);
    var ren = new THREE.WebGLRenderer({ antialias: true, alpha: true }); ren.setSize(w, h); ren.setPixelRatio(window.devicePixelRatio || 1); box.appendChild(ren.domElement);
    var mesh = new THREE.Mesh(g, new THREE.MeshStandardMaterial({ color: 0xe8590c, metalness: 0.1, roughness: 0.6 })); scene.add(mesh);
    scene.add(new THREE.HemisphereLight(0xffffff, 0x444455, 0.9)); var dl = new THREE.DirectionalLight(0xffffff, 0.8); dl.position.set(1, 2, 3); scene.add(dl);
    var radius = size.length() / 2, dist = radius * 2.6; cam.position.set(dist * 0.6, dist * 0.5, dist); cam.lookAt(0, 0, 0);
    mesh.rotation.x = -Math.PI / 2;
    var drag = false, lx = 0, ly = 0;
    function draw() { ren.render(scene, cam); }
    ren.domElement.addEventListener('pointerdown', function (e) { drag = true; lx = e.clientX; ly = e.clientY; ren.domElement.setPointerCapture(e.pointerId); });
    ren.domElement.addEventListener('pointerup', function () { drag = false; });
    ren.domElement.addEventListener('pointermove', function (e) { if (!drag) { return; } mesh.rotation.z += (e.clientX - lx) * 0.01; mesh.rotation.x += (e.clientY - ly) * 0.01; lx = e.clientX; ly = e.clientY; draw(); });
    ren.domElement.addEventListener('wheel', function (e) { e.preventDefault(); cam.position.multiplyScalar(e.deltaY > 0 ? 1.08 : 0.92); draw(); }, { passive: false });
    msg.remove(); info.textContent = 'Size: ' + size.x.toFixed(1) + ' x ' + size.y.toFixed(1) + ' x ' + size.z.toFixed(1) + ' (model units, normally mm) - ' + (pos.length / 9) + ' triangles'; draw();
  }).catch(function (e) { msg.textContent = 'Could not show this model: ' + e.message + '. You can still download it.'; });
})();
