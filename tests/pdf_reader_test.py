"""Synthetic questionnaire: never includes real respondent data."""
import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('reader', Path(__file__).parents[1] / 'scripts/read-survey-pdf.py')
reader = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reader)


def fixture():
    blocks = []
    for band in range(2):
        for index, width in enumerate(reader.WIDTHS):
            header = [reader.ANCHORS[index]]
            if index == 0:
                header += ['Dirección de correo electrónico', '1) Nombre de la Empresa']
            elif index < 15:
                header += [reader.SECOND_ANCHORS[index - 1]]
            data = [['Totalmente de acuerdo'] * width for _ in range(2)]
            if index == 0:
                for row in data:
                    row[0] = '21/10/2026 10:30:00'
            values = [header] + data if band == 0 else data
            blocks.append({'page': len(blocks) + 1, 'values': values,
                           'bounds': [v for i in range(len(values)) for v in (50 + i * 20, 70 + i * 20)]})
    return blocks


class PdfReaderTest(unittest.TestCase):
    def test_horizontal_pages_form_people_not_extra_responses(self):
        result = reader.reconstruct(fixture())[0]
        self.assertEqual((result['row_count'], result['column_count']), (5, 32))
        self.assertEqual(result['rows'][0][14]['value'], 'P01')
        self.assertEqual(result['rows'][3][31]['source'], {'page': 32, 'table_row': 1})

    def test_rejects_missing_page_shifted_rows_and_unknown_template(self):
        for kind in ('page', 'row', 'geometry', 'header', 'second_header', 'timestamp'):
            blocks = copy.deepcopy(fixture())
            if kind == 'page': blocks.pop()
            if kind == 'row': blocks[3]['values'].pop()
            if kind == 'geometry': blocks[3]['bounds'][2] += 3
            if kind == 'header': blocks[3]['values'][0][0] = 'Otro cuestionario'
            if kind == 'second_header': blocks[3]['values'][0][1] = 'Otra pregunta'
            if kind == 'timestamp': blocks[16]['values'][0][0] = 'Otra tabla'
            with self.subTest(kind=kind), self.assertRaises(ValueError):
                reader.reconstruct(blocks)

    def test_preserves_truncated_text_and_enforces_limits(self):
        blocks = fixture()
        blocks[3]['values'][1][1] = 'Texto cortado tal como está'
        result = reader.reconstruct(blocks)[0]
        self.assertEqual(result['rows'][1][8]['value'], 'Texto cortado tal como está')
        with self.assertRaises(ValueError): reader.reconstruct(blocks, max_rows=3)
        with self.assertRaises(ValueError): reader.reconstruct(blocks, max_cells=64)

    def test_real_pdf_fixture_extraction(self):
        result = reader.read(str(Path(__file__).parent / 'Fixtures/employers-paginated.pdf'))[0]
        self.assertEqual(result['row_count'], 5)
        self.assertEqual(result['rows'][3][31]['value'], 'Totalmente de acuerdo')


if __name__ == '__main__':
    unittest.main()
