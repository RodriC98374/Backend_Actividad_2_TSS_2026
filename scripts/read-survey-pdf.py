"""Read the verified, horizontally paginated employer questionnaire export.

No OCR, fuzzy answer completion, or generic PDF-to-row conversion is performed.
Only the recognized questionnaire layout is accepted; source cells remain auditable.
"""
import json
import re
import sys
import unicodedata

import pdfplumber


ANCHORS = [
    'Marca temporal', '2)Tipo de organización',
    '4) Indique el rubro o sector principal',
    '6) Considerando las necesidades actuales',
    '8)¿A través de qué medio convoca',
    '10) Desde su experiencia en el sector laboral',
    '12) Desde su experiencia en el ámbito laboral',
    'Considero que el título profesional otorgado',
    'Conozco el perfil profesional y las competencias',
    'La formación proporcionada por la Carrera',
    'El desempeño profesional de los titulados',
    'He participado en procesos de recopilación',
    'He participado en actividades de vinculación con',
    'He participado en actividades de vinculación entre',
    'Como empleador, la Carrera de Ingeniería',
    'He sido consultado periódicamente por la Carrera',
]
WIDTHS = [3] + [2] * 14 + [1]
SECOND_ANCHORS = [
    '3) Tamaño de la organización', '5) ¿La organización ha contratado',
    '7) ¿Qué nivel de formación académica', '9) ¿Qué tipo de cargos desempeñan',
    '11) ¿Cuáles son las nuevas herramientas', 'La Carrera de Ingeniería de Sistemas',
    'Las autoridades de la Carrera', 'El perfil profesional declarado',
    'La Carrera de Ingeniería de Sistemas', 'Los profesionales titulados de Ingeniería',
    'La Carrera de Ingeniería de Sistemas', 'He participado en actividades de difusión',
    'Como empleador, he sido consultado', 'Cuando nuestra organización requiere',
]
FIELDS = ['Marca temporal'] + [f'E{i:02}' for i in range(13)] + [f'P{i:02}' for i in range(1, 11)] + [f'V{i:02}' for i in range(1, 9)]


def normalized(text):
    text = unicodedata.normalize('NFKD', text or '')
    return re.sub(r'[^a-z0-9]', '', text.lower())


def reconstruct(blocks, max_rows=10000, max_cells=200000):
    """Validate geometry before joining columns. Blocks have values and row bounds."""
    if not blocks or len(blocks) % 16:
        raise ValueError('El PDF no tiene bloques completos de la encuesta de empleadores.')
    if any(not b['values'] or any(len(row) != WIDTHS[i % 16] for row in b['values']) for i, b in enumerate(blocks)):
        raise ValueError('Las columnas del PDF no corresponden a la plantilla de empleadores.')
    for index, anchor in enumerate(ANCHORS):
        if not normalized(blocks[index]['values'][0][0]).startswith(normalized(anchor)):
            raise ValueError('No se reconoce el orden de preguntas del PDF. Usa el Excel original de respuestas.')
    for index, anchor in enumerate(SECOND_ANCHORS, 1):
        # Overflowing headers may interleave glyphs. Require the ordered prefix;
        # this is only template identification, never answer normalization.
        actual = iter(normalized(blocks[index]['values'][0][1]))
        if not all(char in actual for char in normalized(anchor)[:28]):
            raise ValueError('Una pregunta del PDF no coincide con la plantilla reconocida.')
    first = blocks[0]['values'][0]
    if 'correoelectronico' not in normalized(first[1]) or 'nombredelaempresa' not in normalized(first[2]):
        raise ValueError('El PDF no corresponde a la plantilla de respuestas de empleadores.')
    rows = []
    header = []
    column = 0
    for block in blocks[:16]:
        for raw in block['values'][0]:
            header.append({'column_index': column, 'type': 'string', 'value': FIELDS[column],
                           'display_text': FIELDS[column], 'source': {'page': block['page'], 'table_row': 1, 'original_header': raw}})
            column += 1
    rows.append(header)
    for start in range(0, len(blocks), 16):
        band = blocks[start:start + 16]
        count = len(band[0]['values'])
        geometry = band[0]['bounds']
        for index, block in enumerate(band):
            if len(block['values']) != count or any(len(row) != WIDTHS[index] for row in block['values']):
                raise ValueError('Las páginas no tienen filas y columnas compatibles; no se unirán respuestas por suposición.')
            if len(block['bounds']) != len(geometry) or any(abs(a - b) > 0.75 for a, b in zip(block['bounds'], geometry)):
                raise ValueError('Las filas no se alinean entre páginas. Exporta la tabla original a Excel.')
        for row_index in range(1 if start == 0 else 0, count):
            # Every horizontal band must start with real response timestamps.
            timestamp = band[0]['values'][row_index][0] or ''
            if not re.fullmatch(r'\d{1,2}/\d{1,2}/\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?', timestamp.strip()):
                raise ValueError('No se pudo verificar la continuidad de las respuestas del PDF.')
            row = []
            for block in band:
                for local_column, value in enumerate(block['values'][row_index]):
                    value = value.strip() if value else None
                    row.append({'column_index': len(row), 'type': 'string' if value else 'empty',
                                'value': value, 'display_text': value,
                                'pdf_text_clipped': [row_index, local_column] in block.get('clipped', []),
                                'source': {'page': block['page'], 'table_row': row_index + 1}})
            rows.append(row)
            if len(rows) > max_rows or len(rows) * 32 > max_cells:
                raise ValueError('El PDF supera el límite de respuestas o celdas.')
    if len(rows) < 2:
        raise ValueError('El PDF no contiene respuestas.')
    return [{'name': 'Empleadores PDF (revisar textos)', 'sheet_index': 0,
             'row_count': len(rows), 'column_count': 32, 'rows': rows}]


def read(path, max_pages=64, max_rows=10000, max_cells=200000):
    blocks = []
    with pdfplumber.open(path) as document:
        if len(document.pages) > max_pages:
            raise ValueError('El PDF supera el límite de páginas.')
        for number, page in enumerate(document.pages, 1):
            if len(page.chars) > 100000:
                raise ValueError('Una página del PDF es demasiado compleja.')
            tables = page.find_tables()
            if len(tables) != 1:
                raise ValueError('Se requiere una tabla digital por página. No se admiten escaneos ni informes con gráficos.')
            table = tables[0]
            values = table.extract()
            if not values or not values[0]:
                raise ValueError('El PDF contiene una tabla vacía.')
            clipped = []
            for row_index, row in enumerate(table.rows):
                for column_index, bbox in enumerate(row.cells):
                    if bbox is None:
                        raise ValueError('El PDF contiene celdas combinadas no compatibles.')
                    left, top, right, bottom = bbox
                    if any(top < (c['top'] + c['bottom']) / 2 < bottom and
                           ((c['x0'] < right and c['x1'] > right - 1) or
                            (c['x0'] < left and c['x1'] > left)) for c in page.chars):
                        clipped.append([row_index, column_index])
            blocks.append({'page': number, 'values': values,
                           'clipped': clipped,
                           'bounds': [v for row in table.rows for v in (row.bbox[1], row.bbox[3])]})
            page.close()
    return reconstruct(blocks, max_rows, max_cells)


if __name__ == '__main__':
    try:
        if sys.platform != 'win32':
            import resource
            resource.setrlimit(resource.RLIMIT_AS, (512 * 1024 * 1024, 512 * 1024 * 1024))
            resource.setrlimit(resource.RLIMIT_CPU, (40, 40))
        print(json.dumps({'sheets': read(sys.argv[1], *map(int, sys.argv[2:]))}, ensure_ascii=False))
    except ValueError as error:
        print(json.dumps({'error': str(error)}, ensure_ascii=False))
        sys.exit(2)
    except Exception:
        # Do not leak document contents, paths or parser internals to clients.
        print(json.dumps({'error': 'No se pudo leer el PDF. Comprueba que sea una tabla digital sin contraseña.'}))
        sys.exit(2)
