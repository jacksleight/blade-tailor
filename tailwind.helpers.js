export const variantExtract = (content) => [
    ...content.match(/[^"'`\s]*/g),
    ...Array.from(content.matchAll(/'([^\s]+)\:\:\s(.*?)'/g))
        .map((match) => match[2].split(/\s+/).map(name => `${match[1]}:${name}`))
        .flat(),
]