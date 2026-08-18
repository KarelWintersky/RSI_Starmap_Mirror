# shader_data — формат и генерация

## Источники правды

- Движок: `web/static/starmap/starmap.bundle.js` (обфусцированный, ~1.33 МБ)
- Документация движка: `docs/ENGINE.md` (строки 211, 246–260, 342)
- Формат JSON: `docs/UNIVERSE_FORMAT.md` (строки 187–258)

## Три уровня shader_data

### 1. Система (`star_systems.shader_data`)

| Поле | Тип | Описание | Диапазон |
|---|---|---|---|
| `lightColor` | hex | Цвет направленного света | Спектр звезды |
| `starfield.radius` | float | Радиус звёздного поля | 3–60 |
| `starfield.count` | float | Количество звёзд | 100–1500 |
| `starfield.sizeMin` | float | Мин. размер звезды | 0.5–1.5 |
| `starfield.sizeMax` | float | Макс. размер звезды | 1–2 |
| `starfield.color1` | color | Цвет группы 1 | `#616060`..`#ffffff` |
| `starfield.color2` | color | Цвет группы 2 | `rgb(80,80,80)`..`rgb(120,120,120)` |
| `planetsSize.min` | float | Мин. размер планеты | 0.02–0.06 |
| `planetsSize.max` | float | Макс. размер планеты | 0.04–0.08 |
| `planetsSize.kFactor` | float | Экспонента размера | 0.2–0.7 |

### 2. Звезда (`celestial_objects.shader_data.sun`)

| Поле | Тип | Описание | Эвристика |
|---|---|---|---|
| `color1` | color | Основной цвет | Спектр класса |
| `color2` | color | Белый центр | `#ffffff` |
| `flare1..6` | float | Интенсивность 6 лучей | 0.05–0.8 |
| `sphere` | float | Интенсивность сферы | 0.5–1.0 |
| `texture` | float | Интенсивность текстуры | 0.5–1.0 |
| `corona` | float | Корона | 0.3–0.8 |
| `glow` | float | Свечение | 0.3–0.7 |
| `alpha` | float | Прозрачность | 0.8–1.0 |
| `rotation1` | float | Скорость вращения 1 | 0.1–1.0 |
| `rotation2` | float | Скорость вращения 2 | 0.3–1.0 |
| `map` | string | Номер маппинга | `"1"`, `"2"` |
| `iterations` | int | Итерации | 0 |
| `scaleMin` | float | Мин. масштаб | 0.8–1.2 |
| `scaleMax` | float | Макс. масштаб | 1.0–1.5 |
| `scalePeriod` | float | Период пульсации (сек) | 1–60 |

#### Пульсация звезды

Звезда пульсирует, если `scaleMin ≠ scaleMax`. Формула из движка (deobfuscated.js:44499):

```
scale = lerp(scaleMin, scaleMax, (sin(time × 2π / scalePeriod) + 1) / 2)
```

Это синусоида: масштаб плавает между `scaleMin` и `scaleMax` за время `scalePeriod` секунд.

**Как задать в базе:**

```sql
-- Включить пульсацию для звезды NUL (масштаб 1.0–1.11, период 1 сек)
UPDATE celestial_objects
SET shader_data = JSON_SET(shader_data,
  '$.sun.scaleMin', 1.11,
  '$.sun.scaleMax', 1.0,
  '$.sun.scalePeriod', 1
)
WHERE code = 'NUL.STARS.NUL';

-- Медленная пульсация (10 сек), масштаб 0.9–1.2
UPDATE celestial_objects
SET shader_data = JSON_SET(shader_data,
  '$.sun.scaleMin', 0.9,
  '$.sun.scaleMax', 1.2,
  '$.sun.scalePeriod', 10
)
WHERE code = 'MY.STARS.MYSTAR';

-- Выключить пульсацию (статичная звезда)
UPDATE celestial_objects
SET shader_data = JSON_SET(shader_data,
  '$.sun.scaleMin', 1,
  '$.sun.scaleMax', 1
)
WHERE code = 'MY.STARS.MYSTAR';
```

**Примеры из базы:**

| Звезда | scaleMin | scaleMax | period | Эффект |
|---|---|---|---|---|
| NUL | 1.11 | 1.0 | 1 | Быстрая пульсация +11% |
| BANSHEE | 1.0 | 1.4 | 10 | Медленное расширение +40% |
| INDRA A | 0.994 | 1.0 | 2.8 | Еле заметная пульсация |
| ORION | 0.994 | 1.0 | 1 | Еле заметная, быстрая |
| Остальные 82 | 1.0 | 1.0 | — | Статична |

**Совет:** `scalePeriod < 3` — быстрая пульсация (заметна), `> 10` — медленное "дыхание". Разница `scaleMin/scaleMax > 0.1` — заметна визуально, `< 0.05` — еле уловима.

### 3. Планета (`celestial_objects.shader_data`)

| Поле | Тип | Описание |
|---|---|---|
| `orbitalMin` | float | Мин. радиус орбитальной линии |
| `orbitalMax` | float | Макс. радиус орбитальной линии |
| `orbitalFactor` | float | Фактор орбиты (обычно 1) |
| `orbitalColor` | color | Цвет орбиты |
| `orbitalHighlightColor` | color | Цвет орбиты при выборе |
| `highlight.color1` | color | Цвет подсветки 1 |
| `highlight.color2` | color | Цвет подсветки 2 |
| `highlight.alpha` | float | Прозрачность подсветки |
| `highlight.atmosphere1..3` | float | Атмосфера |
| `highlight.scaleMin/Max` | float | Масштаб |
| `highlight.scalePeriod` | float | Период пульсации |
| `ring.*` | object | Кольца (опционально) |

## Спектральные классы → цвета

| Класс | `lightColor` / `sun.color1` | Описание |
|---|---|---|
| O | `#aabfff` | Голубая |
| B | `#a2c0ff` | Сине-белая |
| A | `#d4e0ff` | Белая |
| F | `#f8f7ff` | Жёлто-белая |
| G | `#fff4ea` | Жёлтая (Солнце) |
| K | `#ffd2a1` | Оранжевая |
| M | `#ffcc6f` | Красная |

## Алгоритм генерации (псевдокод)

```
для каждой системы:
  звезда = найти объект type=STAR в системе
  класс = звезда.subtype.name или "G" по умолчанию

  system.shader_data = {
    lightColor: спектр[класс].color,
    starfield: {
      radius: random(5, 50),
      count: random(300, 1200),
      sizeMin: 0.8, sizeMax: 1.5,
      color1: gray(random(80, 180)),
      color2: gray(random(80, 120))
    },
    planetsSize: {
      min: random(0.02, 0.05),
      max: random(0.04, 0.07),
      kFactor: random(0.2, 0.6)
    }
  }

  звезда.shader_data = {
    sun: {
      color1: спектр[класс].color,
      color2: "#ffffff",
      flare1..6: [для каждого random(0.05, 0.7)],
      sphere: random(0.6, 1.0),
      corona: random(0.3, 0.8),
      glow: random(0.3, 0.7),
      alpha: 1,
      rotation1: random(0.2, 0.8),
      rotation2: random(0.4, 1.0),
      texture: 1, map: "2", iterations: 0,
      scaleMin: 1, scaleMax: 1
    }
  }

  для каждой планеты в системе:
    планета.shader_data = {
      orbitalMin: планета.distance * 0.8,
      orbitalMax: max(планета.distance * 1.5, 5),
      orbitalFactor: 1,
      orbitalColor: "rgb(26,86,124)",
      orbitalHighlightColor: "rgb(128,128,255)",
      highlight: {
        color1: "rgb(255,255,255)",
        color2: "rgb(255,255,255)",
        alpha: random(0.6, 1.0),
        atmosphere1: 0.5, atmosphere2: 0.5, atmosphere3: 0.5,
        scaleMin: 1, scaleMax: 1, scalePeriod: 1
      }
    }
    если планета имеет кольца:
      планета.shader_data.ring = {
        color1: random_dark(), color2: random_gray(),
        radius1: random(0.5, 0.8),
        radius2: random(0.85, 1.0),
        speed: random(0.2, 0.5)
      }
```

## Связь с БД

```sql
-- Системы без shader_data
SELECT code, name FROM star_systems WHERE shader_data IS NULL;

-- Звёзды без shader_data
SELECT code, name FROM celestial_objects
WHERE type = 'STAR' AND shader_data IS NULL;

-- Планеты без shader_data
SELECT code, name FROM celestial_objects
WHERE type = 'PLANET' AND shader_data IS NULL;

-- Пульсирующие звёзды (scaleMin ≠ scaleMax)
SELECT code,
  JSON_EXTRACT(shader_data, '$.sun.scaleMin') as min,
  JSON_EXTRACT(shader_data, '$.sun.scaleMax') as max,
  JSON_EXTRACT(shader_data, '$.sun.scalePeriod') as period
FROM celestial_objects
WHERE type = 'STAR'
  AND JSON_EXTRACT(shader_data, '$.sun.scaleMin')
   != JSON_EXTRACT(shader_data, '$.sun.scaleMax');
```
