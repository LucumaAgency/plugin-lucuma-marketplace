# Lucuma Marketplace (plugin WordPress)

Núcleo del marketplace de maquinaria (fase 1, equivalente a Machinery Trader). Outline del proyecto:
Proyectos → Ecosistema Heavy Machinery → Archivos → «Outline del proyecto».

Despliegue: Deployer for Git (archivo principal en la raíz del repo). Tras actualizar, el plugin
refresca solo el rol Dealer y las reglas de URL una vez por versión (`lm_version`).

## Modelo
- `lm_equipment` (REST `/wp/v2/equipment`, URL `/equipment/<slug>/`): un equipo; el autor es el dealer.
- `lm_category` jerárquica (`/for-sale/construction/excavators/`); cada categoría lleva en `lm_specs`
  la lista de especificaciones. Un equipo usa las de su categoría y sus ancestros.
- `lm_make` (`/make/caterpillar/`), `lm_model` (`/model/caterpillar-320/`, meta `lm_make`). Al guardar,
  la marca se alinea con la del modelo.
- Meta: `lm_year`, `lm_hours`, `lm_mileage`, `lm_price`, `lm_call_for_price`, `lm_condition`
  (new/used/refurbished), `lm_status` (available/pending/sold), `lm_stock_number`, `lm_city`,
  `lm_state` (2 letras), `lm_zip` (5 dígitos), `lm_country` (US), `lm_video_url`, `lm_gallery` (ids),
  `lm_specs` (objeto). `lm_serial` es privado: solo lo lee/escribe quien puede editar el equipo.
- Rol `lm_dealer`: crea, publica, edita y borra solo lo suyo; asigna términos pero no edita el catálogo.

## API `lucuma-mkt/v1`
- `GET /catalog`: categorías con sus specs y marcas (público).
- `POST /seed`: carga `data/catalogo.json` (admin; idempotente por slug, pisa las specs).
- `POST /models` `{make, model}`: busca o crea el modelo bajo una marca existente (dealers y admin; la marca acepta alias, p. ej. "Deere").
- `GET /resolve?category=…&make=…`: traduce un nombre (de Sandhills u otro origen) a la categoría o marca del catálogo, por nombre, slug o alias (`lm_aliases`).

## Panel del dealer (0.3.0)
- Página `/dealer/` (shortcode `[lm_dealer_panel]`, se crea sola). App sin dependencias en `assets/panel.js`:
  lista con estado rápido, formulario (categoría → specs, marca → modelos, fotos arrastrando, precio,
  ubicación), perfil de empresa con logo. Publica directo (decisión 2a); "Save as draft" opcional.
- Los dealers no entran al wp-admin ni ven la barra; al iniciar sesión van al panel. Solo ven sus fotos.
- Invitación (decisión 1a): `POST /lucuma-mkt/v1/dealers {email, company, name?, phone?, city?, state?}` →
  enlace para crear contraseña (no depende del correo). También vale Usuarios → Añadir con rol Dealer.
- API del panel: `/my/equipment` (GET/POST), `/my/equipment/{id}` (GET/POST/DELETE),
  `/my/equipment/{id}/status`, `/models?make=`, `/my/profile` (GET/POST).

## Piezas públicas para Bricks (0.4.0)
Shortcodes que Bricks coloca con su elemento Shortcode (diseño en Bricks, lógica aquí; estilos en
`assets/front.css`): `[lm_search]`, `[lm_categories]`, `[lm_makes]`, `[lm_grid count="8"]` (y `main="1"`
en el archivo, con filtros `?q=` `?eqcat=` `?make=` `?dealer=` y paginación), `[lm_filters]`,
`[lm_archive_title]`; en la ficha `[lm_breadcrumbs]` `[lm_price]` `[lm_gallery]` `[lm_facts]` `[lm_specs]`
`[lm_dealer]`. Nunca usar `?cat=` (reservado por WordPress). Vendidos al final del listado.

## Importador (0.5.0)
Panel → Import (`#/import`): plantilla CSV, subir archivo, vista previa por fila (categoría y marca
resueltas con alias; errores marcados) e importación en tandas con barra de avance. Columnas:
`stock_number, category, make, model, year, hours, mileage, price, call_for_price, condition, status,
serial, city, state, zip, title, description, video_url, photos` (+ `spec:<clave>`); acepta alias
(`stock`, `manufacturer`, `vin`, `images`…) y separador `,` o `;`. `photos`: URLs separadas por `|`.
Reimportar actualiza por número de stock (por dealer); las fotos se bajan una vez (`_lm_source_url`).
El admin importa a nombre de un dealer. API: `GET /import/template`, `POST /import` (multipart `file`
o JSON `rows`, `dealer`), `POST /import/{job}/run {limit}`. No lee Sandhills directamente (Cloudflare):
el inventario de un dealer de Sandhills entra como CSV o feed oficial.

## SEO (0.8.0)
`includes/class-lm-seo.php`, encima de SEOPress (sus ajustes generales siguen en SEOPress):
- Títulos y descripciones desde los datos: ficha "2001 Case 1845C Skid Steer for Sale in Carbondale, PA | Heavy Listing"
  (+ precio, horas y dealer en la descripción); categoría "Used Skid Steers for Sale" (raíces: "Construction Equipment",
  "Farm Equipment"…); marca "Used Bobcat Equipment for Sale"; modelo "Used John Deere 1050K for Sale"; archivo
  "Used Heavy Equipment for Sale From Dealers". Filtros `seopress_titles_title` / `seopress_titles_desc`.
- `noindex, follow` (filtro `seopress_titles_robots`) y fuera del sitemap: listados con parámetros (`?q=`, `?eqcat=`…;
  se ignoran `utm_*`, `gclid`, `fbclid`, `msclkid`), marcas y modelos con menos de 2 equipos, categorías vacías, `/dealer/`.
- JSON-LD: Product + Offer (precio USD, disponibilidad según estado, vendedor con dirección) + BreadcrumbList en la
  ficha; Organization + WebSite con SearchAction en la portada. Sin precio ("call for price") no se publica Offer.
- URL de ficha `<título>-<id>` al guardar (única y estable); WordPress redirige el slug viejo. Los equipos existentes
  la reciben al volver a guardarse (un POST REST vacío alcanza).

## Remates (0.9.0)
`includes/class-lm-auctions.php`: calendario de remates (fase A, sin pujas: cada remate enlaza a su catálogo).
- Tipo `lm_auction` (REST `/wp/v2/auctions`, URL `/auctions/<slug>/`, archivo `/auctions/` solo próximos, por fecha;
  filtro `?state=PA`). Meta: `lm_start`/`lm_end` (hora local ET, `YYYY-MM-DDTHH:MM`), `lm_type` (live, online, timed,
  live-online), `lm_auctioneer` (si el rematador no es dealer; si no, el autor), `lm_address`, `lm_city`, `lm_state`,
  `lm_zip`, `lm_url` (catálogo), `lm_featured` (pagado: Auction plan). Caja "Auction details" en el wp-admin.
- Shortcodes: `[lm_auctions count="6"]` (y `main="1"` en el archivo), `[lm_auctions_featured count="3" min="6"]` (destacados; solo con 6+ remates próximos,
  de las próximas 3 semanas, orden al azar en cada visita), `[lm_auction_facts]` (ficha; botón con `data-lm-event="auction_catalog"`).
- SEO (en class-lm-seo.php): título con fecha y lugar, JSON-LD Event (modo presencial/online/mixto), noindex de
  remates terminados y del archivo filtrado.
- Bricks: plantillas "LHM · Auctions archive" y "LHM · Auction single" (`bricks/gen.py`: subastas, subasta).

## Siguiente (outline, orden de trabajo)
Importador CSV/Sandhills · tabla propia de búsqueda + buscador ·
contactos y medición · páginas SEO de categoría/marca/modelo · widget para la web de Kurtz.
