/* assets/js/clinic-3d-scene.js
 * Self-contained Three.js scene — clinic/medical theme
 *
 * Elements:
 *   • DNA double helix (rotating, floating)
 *   • Floating capsule/pill (focal interest)
 *   • Particle field (cells / molecules ambiance)
 *   • Pulse rings (ECG-like expanding rings)
 *   • Brand-green lighting + cyan rim light
 *
 * Safety:
 *   • Skips on prefers-reduced-motion
 *   • Pauses when tab hidden (perf)
 *   • Capped pixel ratio (max 2)
 *   • WebGL feature detection — returns null if not supported
 *
 * Usage:
 *   <canvas id="scene3d"></canvas>
 *   <script src="three.min.js"></script>
 *   <script src="clinic-3d-scene.js"></script>
 *   <script>ClinicScene.init(document.getElementById('scene3d'));</script>
 */
(function () {
    'use strict';

    function hasWebGL() {
        try {
            const c = document.createElement('canvas');
            return !!(window.WebGLRenderingContext &&
                (c.getContext('webgl2') || c.getContext('webgl') || c.getContext('experimental-webgl')));
        } catch (e) { return false; }
    }

    const ClinicScene = {
        init(canvas, opts) {
            opts = opts || {};
            if (!canvas) return null;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return null;
            if (!hasWebGL()) return null;
            if (!window.THREE) { console.warn('[ClinicScene] THREE.js not loaded'); return null; }

            const THREE = window.THREE;
            const cfg = Object.assign({
                colorPrimary:  0x4dc98a,
                colorAccent:   0x06c2a4,
                colorCapsule:  0xff6b6b,
                particles:     180,
                helixPairs:    48,
                helixLen:      24,
            }, opts);

            // ─── Renderer ────────────────────────────────────────
            const renderer = new THREE.WebGLRenderer({
                canvas, antialias: true, alpha: true, powerPreference: 'high-performance',
            });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
            renderer.setSize(canvas.clientWidth, canvas.clientHeight, false);
            if (renderer.outputColorSpace !== undefined) renderer.outputColorSpace = THREE.SRGBColorSpace;

            // ─── Scene + fog (depth haze) ───────────────────────
            const scene = new THREE.Scene();
            scene.fog = new THREE.FogExp2(0x041710, 0.022);

            // ─── Camera ──────────────────────────────────────────
            const camera = new THREE.PerspectiveCamera(
                60, canvas.clientWidth / canvas.clientHeight, 0.1, 200
            );
            camera.position.set(0, 0, 16);

            // ─── Lighting ────────────────────────────────────────
            scene.add(new THREE.AmbientLight(0xffffff, 0.35));
            const keyLight = new THREE.DirectionalLight(cfg.colorPrimary, 1.1);
            keyLight.position.set(6, 8, 6);
            scene.add(keyLight);
            const rimLight = new THREE.PointLight(cfg.colorAccent, 1.6, 36, 1.4);
            rimLight.position.set(-7, -4, 6);
            scene.add(rimLight);
            const fillLight = new THREE.PointLight(0xffffff, 0.4, 30, 2);
            fillLight.position.set(0, 6, 8);
            scene.add(fillLight);

            // ─── DNA Double Helix ────────────────────────────────
            const helix = new THREE.Group();
            const sphereGeo = new THREE.SphereGeometry(0.17, 16, 16);
            const matA = new THREE.MeshPhongMaterial({
                color: cfg.colorPrimary, shininess: 90, specular: 0xffffff,
                emissive: cfg.colorPrimary, emissiveIntensity: 0.22,
            });
            const matB = new THREE.MeshPhongMaterial({
                color: cfg.colorAccent, shininess: 90, specular: 0xffffff,
                emissive: cfg.colorAccent, emissiveIntensity: 0.28,
            });
            const matRung = new THREE.MeshBasicMaterial({
                color: 0xffffff, transparent: true, opacity: 0.32,
            });

            for (let i = 0; i < cfg.helixPairs; i++) {
                const t = i / cfg.helixPairs;
                const y = (t - 0.5) * cfg.helixLen;
                const a = t * Math.PI * 6;
                const r = 2.2;

                const sA = new THREE.Mesh(sphereGeo, matA);
                sA.position.set(Math.cos(a) * r, y, Math.sin(a) * r);
                helix.add(sA);

                const sB = new THREE.Mesh(sphereGeo, matB);
                sB.position.set(Math.cos(a + Math.PI) * r, y, Math.sin(a + Math.PI) * r);
                helix.add(sB);

                if (i % 3 === 0) {
                    const dx = sB.position.x - sA.position.x;
                    const dz = sB.position.z - sA.position.z;
                    const len = Math.sqrt(dx * dx + dz * dz);
                    const rung = new THREE.Mesh(
                        new THREE.CylinderGeometry(0.028, 0.028, len, 6),
                        matRung
                    );
                    rung.position.set((sA.position.x + sB.position.x) / 2, y, (sA.position.z + sB.position.z) / 2);
                    rung.rotation.z = Math.PI / 2;
                    rung.rotation.y = -Math.atan2(dz, dx);
                    helix.add(rung);
                }
            }
            helix.position.x = -3.5;
            scene.add(helix);

            // ─── Pill / Capsule (focal element on right) ─────────
            let capsule;
            if (typeof THREE.CapsuleGeometry === 'function') {
                capsule = new THREE.Mesh(
                    new THREE.CapsuleGeometry(0.5, 1.4, 8, 18),
                    new THREE.MeshPhongMaterial({
                        color: cfg.colorCapsule, shininess: 120, specular: 0xffffff,
                        emissive: cfg.colorCapsule, emissiveIntensity: 0.15,
                    })
                );
            } else {
                // Fallback for older Three.js — torus-knot stand-in
                capsule = new THREE.Mesh(
                    new THREE.TorusKnotGeometry(0.6, 0.2, 80, 12),
                    new THREE.MeshPhongMaterial({
                        color: cfg.colorCapsule, shininess: 120, specular: 0xffffff,
                    })
                );
            }
            capsule.position.set(5.5, 2, 2);
            capsule.rotation.z = Math.PI / 4;
            scene.add(capsule);

            // ─── Particle field (cells / molecules) ──────────────
            const pGeo = new THREE.BufferGeometry();
            const pPos = new Float32Array(cfg.particles * 3);
            for (let i = 0; i < cfg.particles; i++) {
                pPos[i * 3 + 0] = (Math.random() - 0.5) * 55;
                pPos[i * 3 + 1] = (Math.random() - 0.5) * 38;
                pPos[i * 3 + 2] = (Math.random() - 0.5) * 30 - 4;
            }
            pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
            const pMat = new THREE.PointsMaterial({
                color: cfg.colorPrimary, size: 0.10, sizeAttenuation: true,
                transparent: true, opacity: 0.78, depthWrite: false,
                blending: THREE.AdditiveBlending,
            });
            const particles = new THREE.Points(pGeo, pMat);
            scene.add(particles);

            // ─── Pulse rings (ECG-like, around capsule) ─────────
            const rings = [];
            for (let i = 0; i < 3; i++) {
                const mesh = new THREE.Mesh(
                    new THREE.RingGeometry(0.45, 0.5, 64),
                    new THREE.MeshBasicMaterial({
                        color: cfg.colorPrimary, transparent: true, side: THREE.DoubleSide,
                    })
                );
                mesh.position.set(5.5, 2, 2);
                mesh.userData.delay = i * 1.16;
                rings.push(mesh);
                scene.add(mesh);
            }

            // ─── Animation loop ──────────────────────────────────
            const clock = new THREE.Clock();
            let running = true;
            let rafId = 0;
            let mouseX = 0, mouseY = 0;

            const onMouse = (e) => {
                mouseX = (e.clientX / window.innerWidth  - 0.5);
                mouseY = (e.clientY / window.innerHeight - 0.5);
            };
            window.addEventListener('mousemove', onMouse, { passive: true });

            function tick() {
                if (!running) return;
                const t  = clock.getElapsedTime();
                const dt = clock.getDelta();

                // DNA rotation + bob
                helix.rotation.y += dt * 0.42;
                helix.position.y = Math.sin(t * 0.6) * 0.4;

                // Particles slow drift
                particles.rotation.y += dt * 0.06;
                particles.rotation.x += dt * 0.02;

                // Pulse rings expanding + fading
                for (let i = 0; i < rings.length; i++) {
                    const r = rings[i];
                    const phase = ((t + r.userData.delay) % 3.5) / 3.5;
                    const s = 0.2 + phase * 6;
                    r.scale.set(s, s, s);
                    r.material.opacity = (1 - phase) * 0.55;
                    r.lookAt(camera.position);
                }

                // Capsule float + spin
                capsule.rotation.x = t * 0.32;
                capsule.rotation.y = t * 0.45;
                capsule.position.y = 2 + Math.sin(t * 0.85) * 0.55;

                // Camera parallax follows mouse
                camera.position.x += (mouseX * 1.4 - camera.position.x) * 0.04;
                camera.position.y += (-mouseY * 1.0 - camera.position.y) * 0.04;
                camera.lookAt(0, 0, 0);

                renderer.render(scene, camera);
                rafId = requestAnimationFrame(tick);
            }

            // ─── Resize handling ─────────────────────────────────
            function resize() {
                const w = canvas.clientWidth, h = canvas.clientHeight;
                if (w === 0 || h === 0) return;
                camera.aspect = w / h;
                camera.updateProjectionMatrix();
                renderer.setSize(w, h, false);
            }
            window.addEventListener('resize', resize);

            // ─── Pause when tab hidden (saves battery) ──────────
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    running = false;
                    if (rafId) cancelAnimationFrame(rafId);
                } else if (!running) {
                    running = true;
                    clock.start();
                    tick();
                }
            });

            tick();

            return {
                scene, camera, renderer,
                helix, particles, rings, capsule,
                destroy() {
                    running = false;
                    if (rafId) cancelAnimationFrame(rafId);
                    window.removeEventListener('mousemove', onMouse);
                    window.removeEventListener('resize', resize);
                    renderer.dispose();
                },
            };
        },
    };

    window.ClinicScene = ClinicScene;
})();
