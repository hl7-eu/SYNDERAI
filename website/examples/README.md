# Content of the example folder of the SYNDERAI website

This is the `examples` folder of the SYNDERAI website.

It contains 

- 1 folder per artifact (*artifact-folder*)
- 1 info file named info-{artifact}.txt per per artifact (*artifact-info*)

With an *artifact-folder* the publications from the SYNDERAI Workbench are included, e.g. 

```
examples
|
+-- LAB
    +-- 1.0.0+20251023
    +-- 2.0.0+20260318
```

The SYNDERAI website exposes only the most recent examples based on the semver of the artifact.

Each of these folders contain 

- all SYNDERAI Bundles at root level in JSON and XML format (for easier rendition using [vi7eti](https://vi7eti.net)), 
- a raw `package` folder, constructed as a FHIR package and
- a `package.tgz` for download (link is also offered by the SYNDERAI website script).

Each *artifact-info* file contains information about the corresponding artifact, used by the SYNDERAI website scripts. The files look like this example.

```
# title | short | description | vi7eti focus | mdi icon | icon color from CSS/SCSS
European Laboratory Report | EU LAB | HL7 FHIR example instances of the European Laboratory Report (LAB) | LAB | mdi-test-tube | vi7eti_green
```

