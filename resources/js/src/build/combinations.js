/**
 * Generates all combinations of object keys (sorted alphabetically, hyphen-joined) 
 * as keys in a returned object, with corresponding value arrays.
 * 
 * Needed to concate css files for all possible component combinations.
 * 
 * Formal: "All k-combinations (without repetition) of an n-set for k = 1 through n."
 * This is the union of all subsets of the power set (excluding the empty set),
 * where each set is represented as a sorted string.
 * The total number is 2^n − 1
 * 
 * example:
 * import { generateKeyCombinations } from './combinations.js';
 * const obj = {
 *   c: "C",
 *   a: ["A1", "A2"],
 *   b: "B"
 * };
 * console.log(generateKeyCombinations(obj));
 *   {
 *     a: ["A1", "A2"],
 *     b: ["B"],
 *     c: ["C"],
 *     a-b: ["A1", "A2", "B"],
 *     a-c: ["A1", "A2", "C"],
 *     b-c: ["B", "C"],
 *     a-b-c: ["A1", "A2", "B", "C"]
 *   }
 * 
 * 
 * @param {Object<string, string>} obj - Input object with string keys and values.
 * @returns {Object<string, Array<string>>} Object with combination keys and value arrays.
 */
export function generateKeyCombinations(obj) {
    const keys = Object.keys(obj).sort();
    const n = keys.length;
    const result = {};

    for (let length = 1; length <= n; length++) {
        const combinations = generateCombinations(keys, length);

        for (const combination of combinations) {
            const keyStr = combination.join('-');
            const values = combination.flatMap((key) => toArray(obj[key]));
            result[keyStr] = values;
        }
    }

    return result;
}

function generateCombinations(keys, length) {
    const result = [];
    const n = keys.length;

    function recurse(start, current) {
        if (current.length === length) {
            result.push([...current]);
            return;
        }
        for (let i = start; i < n; i++) {
            current.push(keys[i]);
            recurse(i + 1, current);
            current.pop();
        }
    }

    recurse(0, []);
    return result;
}

function toArray(value) {
    return Array.isArray(value) ? value : [value];
}