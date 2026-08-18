import * as THREE from 'three';

export const DEG2RAD = Math.PI / 180;

export const Types = {
  STAR: 0,
  PLANET: 1,
  SATELLITE: 2,
  ASTEROID_BELT: 3,
  ASTEROID_FIELD: 4,
  ANOMALY: 5,
  MANMADE: 6,
  JUMPPOINT: 7,
  LZ: 8,
  BLACKHOLE: 9,
  POI: 10,
  OORT: 11,
};

export const TypeNames = {
  STAR: 'звезда',
  PLANET: 'планета',
  SATELLITE: 'спутник',
  ASTEROID_BELT: 'пояс астероидов',
  ASTEROID_FIELD: 'поле астероидов',
  ANOMALY: 'аномалия',
  MANMADE: 'станция',
  JUMPPOINT: 'гиперканал',
  LZ: 'посадочная зона',
  BLACKHOLE: 'чёрная дыра',
  POI: 'точка интереса',
  OORT: 'облако Оорта',
};

// Позиция системы в галактике (ENGINE.md §4.1): x→x, y→z, z→−y, y-up.
export function sysWorld(x, y, z, scale = 1) {
  return new THREE.Vector3(x * scale, z * scale, -y * scale);
}

// Сферические координаты объекта → декартовы внутри системы (ENGINE.md §4.2).
export function sphToCart(distance, latitude, longitude) {
  const lat = latitude * DEG2RAD;
  const lon = -longitude * DEG2RAD;
  const cosLat = Math.cos(lat);
  return new THREE.Vector3(
    distance * cosLat * Math.cos(lon),
    distance * Math.sin(lat),
    -distance * cosLat * Math.sin(lon),
  );
}

export function clamp(v, a, b) {
  return Math.max(a, Math.min(b, v));
}

export function lerp(a, b, t) {
  return a + (b - a) * t;
}

export function dampAngle(a, b, lambda, dt) {
  let d = (b - a) % (2 * Math.PI);
  if (d > Math.PI) d -= 2 * Math.PI;
  if (d < -Math.PI) d += 2 * Math.PI;
  return a + (1 - Math.exp(-lambda * dt)) * d;
}
