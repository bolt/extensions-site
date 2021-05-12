import {annotate} from 'https://unpkg.com/rough-notation?module';

const n1 = document.querySelector('span.tag.is-warning');
const n2 = document.querySelector('span.tag.is-primary');

const a1 = annotate(n1, {type: 'underline', color: 'blue'});
const a2 = annotate(n2, {type: 'underline', color: 'red'});

a1.show();
a2.show();

