<?php
namespace SimpleMappr;

final class CitationDocument
{
    public static function parse(mixed $input, ?int $id = null): array
    {
        if (!is_array($input) || array_diff(array_keys($input), ['id','year','reference','doi','link','first_author_surname'])) {
            throw new HttpException(422, 'Submit only the supported citation fields.');
        }
        if (isset($input['id']) && $input['id'] !== '' && ((!is_string($input['id']) && !is_int($input['id'])) || !ctype_digit((string)$input['id']) || $id === null || (int)$input['id'] !== $id)) {
            throw new HttpException(422, 'The citation identifier does not match this request.');
        }
        $values = [];
        foreach (['year','reference','doi','link','first_author_surname'] as $field) {
            $value = $input[$field] ?? '';
            if ((!is_string($value) && !is_int($value)) || !mb_check_encoding((string)$value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', (string)$value)) {
                throw new HttpException(422, 'Citation fields must contain valid UTF-8 text.');
            }
            if (in_array($field, ['doi','link'], true) && preg_match('/[\x00-\x20\x7F]/', (string)$value)) { throw new HttpException(422, 'DOI and link fields must not contain whitespace.'); }
            $values[$field] = trim((string)$value);
        }
        if (!preg_match('/^[1-9][0-9]{3}$/D', $values['year'])) {
            throw new HttpException(422, 'Use a four-digit publication year between 1000 and 9999.');
        }
        if ($values['reference'] === '' || strlen($values['reference']) > 16384) {
            throw new HttpException(422, 'Enter a reference of at most 16384 UTF-8 bytes.');
        }
        if ($values['first_author_surname'] === '' || mb_strlen($values['first_author_surname']) > 255 || preg_match('/[\r\n\t]/', $values['first_author_surname'])) {
            throw new HttpException(422, 'Enter an author surname of at most 255 characters.');
        }
        if ($values['doi'] !== '' && (strlen($values['doi']) > 255 || !preg_match('~^10\.[0-9]{4,9}/[^\s<>]+$~D', $values['doi']))) {
            throw new HttpException(422, 'Enter a DOI beginning with 10. and its publisher prefix.');
        }
        if ($values['link'] !== '' && !self::validLink($values['link'])) {
            throw new HttpException(422, 'Enter an HTTP or HTTPS link of at most 255 characters without credentials.');
        }
        $values['year'] = (int)$values['year'];
        return $values;
    }

    public static function validLink(string $value): bool
    {
        $url = parse_url($value);
        return mb_strlen($value) <= 255 && !preg_match('/[\s\\\\]/', $value) && filter_var($value, FILTER_VALIDATE_URL) !== false
            && is_array($url) && in_array(strtolower($url['scheme'] ?? ''), ['http','https'], true)
            && !isset($url['user']) && !isset($url['pass']) && isset($url['host']);
    }
}
